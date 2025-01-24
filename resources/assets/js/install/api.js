// Fetch.js
const _apiHost = '/install/api';

/**
 * Extended options for the Fetch request.
 * 
 * @typedef {Object} FetchOptions
 * @property {Record<string, string>} [params] - Query parameter key-value pairs to be appended to the URL.
 * @property {'default' | 'no-store' | 'reload' | 'no-cache' | 'force-cache' | 'only-if-cached'} [cache] - Controls how the request can be cached.
 * @property {'high' | 'low' | 'auto'} [priority] - Indicates the importance of the request.
 * @property {'follow' | 'error' | 'manual'} [redirect] - Controls the behavior of following HTTP redirects.
 * @property {boolean|function(Response)} errorHandler=true - Whether to show a toast message on error.
 */

/**
 * Defines the API's structure for making HTTP requests.
 *
 * @typedef {Object} Api
 * @property {function(string|URL, Record<string, string>, RequestInit & FetchOptions): Promise<Response>} get - Makes a GET request using the Fetch API.
 * @property {function(string|URL, Record<string, any>|FormData|URLSearchParams, RequestInit & FetchOptions): Promise<Response>} post - Makes a POST request.
 * @property {function(string|URL, Record<string, any>|FormData|URLSearchParams, RequestInit & FetchOptions): Promise<Response>} put - Makes a PUT request.
 * @property {function(string|URL, RequestInit & FetchOptions): Promise<Response>} delete - Makes a DELETE request.
 */

/**
 * Makes an HTTP request using the Fetch API.
 *
 * @param {string} [method='GET'] - The HTTP method.
 * @param {string|URL} url - The URL to send the request to.
 * @param {RequestInit & FetchOptions} [options={}] - Additional options for the request.
 * @returns {Promise<Response>} A Promise that resolves to the Response object representing the response to the request.
 */
async function request(
    method = 'GET',
    url,
    options = {}
) {
    const opts = {
        method,
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        }
    };

    if (!(url instanceof URL)) {
        try {
            url = new URL(url);
        } catch (error) {
            url = new URL(
                (_apiHost + "/" + url).replace(/([^:]\/)\/+/g, "$1"),
                window.location.origin
            )
        }
    }

    if (options.params) {
        Object.keys(options.params).forEach(key => url.searchParams.append(key, options.params[key]));
        delete options.params;
    }

    options = { ...opts, ...options };

    if (options.body) {
        if (options.body instanceof FormData) {
            // FormData already sets the correct Content-Type with the boundary
            delete options.headers['Content-Type'];
        } else if (options.body instanceof URLSearchParams) {
            options.headers['Content-Type'] = 'application/x-www-form-urlencoded';
        } else if (options.headers['Content-Type'] === 'application/json' && typeof options.body !== 'string') {
            options.body = JSON.stringify(options.body);
        }
    }


    return fetch(url, { ...opts, ...options }).then((response) => {
        if (response.ok) {
            return response;
        }

        throw response;
    });
}

/**
 * Makes a GET request using the Fetch API.
 *
 * @param {string|URL} url - The URL to send the request to.
 * @param {Record<string, string>} [params={}] - Query parameter key-value pairs to be appended to the URL.
 * @param {RequestInit & FetchOptions} [options={}] - Additional options for the request.
 * @returns {Promise<Response>} A Promise that resolves to the Response object representing the response to the request.
 */
function get(url, params = {}, options = {}) {
    return request('GET', url, { ...options, params });
}

/**
 * Makes a POST request using the Fetch API.
 *
 * @param {string|URL} url - The URL to send the request to.
 * @param {Record<string, any>|FormData|URLSearchParams} [body={}] - The body of the request.
 * @param {RequestInit & FetchOptions} [options={}] - Additional options for the request.
 * @returns {Promise<Response>} A Promise that resolves to the Response object representing the response to the request.
 */
function post(url, body = {}, options = {}) {
    return request('POST', url, { ...options, body });
}

/**
 * Makes a PUT request using the Fetch API.
 *
 * @param {string|URL} url - The URL to send the request to.
 * @param {Record<string, any>|FormData|URLSearchParams} [body={}] - The body of the request.
 * @param {RequestInit & FetchOptions} [options={}] - Additional options for the request.
 * @returns {Promise<Response>} A Promise that resolves to the Response object representing the response to the request.
 */
function put(url, body = {}, options = {}) {
    return request('PUT', url, { ...options, body });
}

/**
 * Makes a DELETE request using the Fetch API.
 *
 * @param {string|URL} url - The URL to send the request to.
 * @param {RequestInit & FetchOptions} [options={}] - Additional options for the request.
 * @returns {Promise<Response>} A Promise that resolves to the Response object representing the response to the request.
 */
function remove(url, options) {
    return request('DELETE', url, options);
}

// Fetch API wrapper
const api = {
    get,
    post,
    put,
    delete: remove
};

/**
 * The default export of the Fetch.js module, providing an API for making HTTP requests.
 * 
 * @type {Api}
 */
export default api;