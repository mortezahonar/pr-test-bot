/**
 * Telegram -> GitHub bridge for pr-test-bot.
 *
 * Telegram sends every update here. The update goes into Workers KV and the
 * workflow is woken up with only the KV key, so message text never lands in the
 * event payload of the (public) repository.
 */

const SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';
const PENDING_PREFIX = '/pending/';

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    if (request.method === 'GET' && url.pathname.startsWith(PENDING_PREFIX)) {
      return fetchPending(request, env, url);
    }

    if (request.method !== 'POST') {
      return new Response('ok');
    }

    if (request.headers.get(SECRET_HEADER) !== env.TELEGRAM_SECRET) {
      return new Response('forbidden', { status: 403 });
    }

    const update = await request.text();
    const key = crypto.randomUUID().replace(/-/g, '');

    await env.UPDATES.put(key, update, { expirationTtl: 3600 });

    const response = await fetch(`https://api.github.com/repos/${env.REPO}/dispatches`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${env.GH_TOKEN}`,
        'Accept': 'application/vnd.github+json',
        'User-Agent': 'pr-bot-hook',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ event_type: 'telegram', client_payload: { key } }),
    });

    if (!response.ok) {
      const detail = await response.text();
      await env.UPDATES.delete(key);

      // A non-2xx answer makes Telegram retry, so the update is not lost.
      return new Response(`dispatch failed: ${response.status} ${detail}`, { status: 502 });
    }

    return new Response('ok', { status: 200 });
  },
};

async function fetchPending(request, env, url) {
  if (request.headers.get('X-Bot-Secret') !== env.BOT_SECRET) {
    return new Response('forbidden', { status: 403 });
  }

  const key = url.pathname.slice(PENDING_PREFIX.length);
  const stored = await env.UPDATES.get(key);

  if (stored === null) {
    return new Response('not found', { status: 404 });
  }

  await env.UPDATES.delete(key);

  return new Response(stored, {
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
}
