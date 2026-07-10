export async function apiJson(path, options = {}) {
  const headers = {
    Accept: 'application/json',
    ...(options.headers || {})
  };

  if (options.body && !(options.body instanceof FormData) && !headers['Content-Type'])
    headers['Content-Type'] = 'application/json';

  const response = await fetch(path, {
    ...options,
    credentials: 'same-origin',
    headers
  });
  const text = await response.text();
  let payload = null;

  if (text !== '') {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = text;
    }
  }

  if (!response.ok) {
    const message = payload && typeof payload === 'object' && payload.error
      ? payload.error
      : response.statusText;
    throw new Error(message || `HTTP ${response.status}`);
  }

  return payload;
}

export async function apiText(path, options = {}) {
  const response = await fetch(path, {
    ...options,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/xml,text/plain,application/json',
      ...(options.headers || {})
    }
  });
  const text = await response.text();

  if (!response.ok) {
    let message = response.statusText;
    try {
      const payload = JSON.parse(text);
      if (payload && payload.error)
        message = payload.error;
    } catch {
      if (text.trim() !== '')
        message = text.trim();
    }
    throw new Error(message || `HTTP ${response.status}`);
  }

  return text;
}

export function isAbortError(error) {
  return error?.name === 'AbortError';
}
