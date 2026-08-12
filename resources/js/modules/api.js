// A thin fetch wrapper for the session-authenticated JSON API. Plan 3 put
// PreventRequestForgery on the API middleware group, so every non-GET
// request must carry the X-CSRF-TOKEN header read from the csrf-token meta
// tag the layout renders, or it comes back 419. GET also needs the session
// cookie (auth:web), so credentials: 'same-origin' applies to every call.

export class ApiError extends Error {
    constructor(message, status) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
    }
}

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');

    return meta ? meta.getAttribute('content') : null;
}

async function request(method, path, body) {
    const headers = {
        Accept: 'application/json',
    };

    let payload;

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    if (method !== 'GET') {
        headers['X-CSRF-TOKEN'] = csrfToken();
    }

    const response = await fetch(path, {
        method,
        headers,
        credentials: 'same-origin',
        body: payload,
    });

    if (!response.ok) {
        throw new ApiError(`${method} ${path} failed with status ${response.status}`, response.status);
    }

    if (response.status === 204) {
        return null;
    }

    return response.json();
}

export function get(path) {
    return request('GET', path);
}

export function post(path, body) {
    return request('POST', path, body);
}

export function patch(path, body) {
    return request('PATCH', path, body);
}

export function del(path) {
    return request('DELETE', path);
}
