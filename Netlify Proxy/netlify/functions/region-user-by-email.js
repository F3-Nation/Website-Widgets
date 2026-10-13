// F3 User-by-Email Lookup — Netlify Serverless Function
// Resolves an email address to an F3 Nation user ID.
// Used by my-qs.html so users don't have to know their numeric userId.
//
// Query params:
//   email (required) — the F3 Nation email address to look up

exports.handler = async function(event, context) {
  const email = event.queryStringParameters?.email;
  if (!email) {
    return {
      statusCode: 400,
      body: JSON.stringify({ error: 'email parameter is required' })
    };
  }

  const headers = event.headers || {};
  const userToken = headers['x-f3-token'] || headers['X-F3-Token'];
  const token = (typeof userToken === 'string' && userToken.trim()) || process.env.F3_BEARER_TOKEN;
  if (!token) {
    return {
      statusCode: 503,
      body: JSON.stringify({ error: 'No bearer token available. Set F3_BEARER_TOKEN or pass one in the X-F3-Token header.' })
    };
  }

  const url = `https://api.f3nation.com/v1/user/email/${encodeURIComponent(email)}?includePii=false`;

  try {
    const response = await fetch(url, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'client': 'f3-my-qs',
      }
    });

    if (response.status === 404) {
      return {
        statusCode: 404,
        headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' },
        body: JSON.stringify({ error: 'No F3 user found for that email' })
      };
    }
    if (!response.ok) {
      return {
        statusCode: 502,
        body: JSON.stringify({ error: `F3 Nation API returned ${response.status}` })
      };
    }

    const raw = await response.json();
    const user = raw && raw.user ? raw.user : raw;
    const id   = user?.id ?? raw?.id;
    const f3Name = user?.f3Name ?? raw?.f3Name ?? null;

    if (!id) {
      return {
        statusCode: 404,
        body: JSON.stringify({ error: 'User found but no ID returned' })
      };
    }

    return {
      statusCode: 200,
      headers: {
        'Content-Type': 'application/json',
        'Access-Control-Allow-Origin': '*',
        'Cache-Control': 'private, max-age=86400',
      },
      body: JSON.stringify({ id, f3Name }),
    };
  } catch (err) {
    console.error('[F3 User Lookup] proxy failed:', err && err.message ? err.message : 'unknown');
    return {
      statusCode: 502,
      body: JSON.stringify({ error: 'Could not reach F3 Nation API.' })
    };
  }
};
