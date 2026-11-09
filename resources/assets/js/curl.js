/**
 * "Copy as cURL" for captured requests.
 *
 * Every part of a captured request (method, URL including the Host header, header
 * names and values, body) is chosen by whoever sent it, and users paste the result
 * into a shell. Each value is therefore emitted as one single-quoted shell word; a
 * single quote inside a value is written as '\'' (end quote, escaped quote, reopen).
 * The body is passed with --data-raw so that a body starting with "@" is sent as-is
 * instead of making curl upload a local file.
 */

function shellQuote(value) {
    return "'" + String(value).replace(/'/g, "'\\''") + "'";
}

function requestToCurl(request) {
    var parts = ['curl', '-X', shellQuote(request.method), shellQuote(request.url)];

    for (var header in request.headers) {
        if (!request.headers.hasOwnProperty(header)) {
            continue;
        }
        parts.push('-H', shellQuote(header + ': ' + request.headers[header]));
    }

    if (request.content !== null && request.content !== undefined && request.content !== '') {
        parts.push('--data-raw', shellQuote(request.content));
    }

    return parts.join(' ');
}

module.exports = {
    shellQuote: shellQuote,
    requestToCurl: requestToCurl
};
