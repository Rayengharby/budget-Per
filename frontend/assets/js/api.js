// ── Fetch wrapper ────────────────────────────────────────────
async function apiFetch(path, options = {}) {
  const token = Auth.getToken();
  const url   = `${API_URL}/${path.replace(/^\//, '')}`;

  try {
    const res = await fetch(url, {
      headers: {
        'Content-Type':  'application/json',
        'Authorization': token ? `Bearer ${token}` : '',
        ...options.headers,
      },
      ...options,
    });

    // Handle non-JSON responses gracefully
    const contentType = res.headers.get('Content-Type') || '';
    if (contentType.includes('application/pdf')) {
      return res; // Return raw response for PDF
    }

    let data;
    try {
      data = await res.json();
    } catch {
      data = { success: false, message: 'Réponse invalide du serveur.' };
    }

    if (res.status === 401) { Auth.logout(); return data; }

    return data;
  } catch (err) {
    // Network error or CORS failure
    return { success: false, message: 'Erreur de connexion au serveur. Vérifiez que XAMPP est démarré.' };
  }
}

const api = {
  get:    (path)         => apiFetch(path),
  post:   (path, body)   => apiFetch(path, { method: 'POST',   body: JSON.stringify(body) }),
  put:    (path, body)   => apiFetch(path, { method: 'PUT',    body: JSON.stringify(body) }),
  patch:  (path, body)   => apiFetch(path, { method: 'PATCH',  body: JSON.stringify(body) }),
  delete: (path)         => apiFetch(path, { method: 'DELETE' }),
};
