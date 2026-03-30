// F3 Schedule Proxy — Netlify Serverless Function
// Works with Netlify Drop (no CLI or GitHub required)

exports.handler = async function(event, context) {
  const regionId = event.queryStringParameters?.regionOrgId;

  if (!regionId) {
    return {
      statusCode: 400,
      body: JSON.stringify({ error: 'regionOrgId parameter is required' })
    };
  }

  const token = process.env.F3_BEARER_TOKEN;
  if (!token) {
    return {
      statusCode: 503,
      body: JSON.stringify({ error: 'F3_BEARER_TOKEN environment variable not set in Netlify dashboard' })
    };
  }

  const today = new Date().toISOString().split('T')[0];
  const url = `https://api.f3nation.com/v1/event-instance/calendar-home-schedule`
    + `?regionOrgId=${regionId}&userId=1&startDate=${today}&limit=150`;

  try {
    const response = await fetch(url, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'client': 'f3-schedule-proxy',
      }
    });

    if (!response.ok) {
      return {
        statusCode: 502,
        body: JSON.stringify({ error: `F3 Nation API returned ${response.status}` })
      };
    }

    const data = await response.text();
    return {
      statusCode: 200,
      headers: {
        'Content-Type': 'application/json',
        'Access-Control-Allow-Origin': '*',
        'Cache-Control': 'public, max-age=1800',
      },
      body: data
    };
  } catch (err) {
    return {
      statusCode: 502,
      body: JSON.stringify({ error: 'Could not reach F3 Nation API: ' + err.message })
    };
  }
};
