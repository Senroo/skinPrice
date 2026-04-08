const API_BASE_URL = 'http://localhost:8080';

export async function fetchJson(path) {
  const response = await fetch(`${API_BASE_URL}${path}`);

  if (!response.ok) {
    throw new Error(`API error on ${path}: ${response.status}`);
  }

  return response.json();
}

