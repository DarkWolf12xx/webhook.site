// Regression tests for "Copy as cURL" (resources/assets/js/curl.js).
// Run with: node --test tests/js/*.test.js
//
// The previous implementation concatenated sender-controlled values into a
// single-quoted command line, so a quote in a header, URL or body let whoever sent
// a webhook run commands on the machine of the user who pasted the command.
'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { requestToCurl, shellQuote } = require('../../resources/assets/js/curl.js');

// Evaluates the generated command in bash with `curl` replaced by a function that
// prints its arguments, and reports whether any injected command ran.
function runInShell(command) {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'curl-test-'));
    try {
        const script = 'curl() { printf "%s\\0" "$@"; }\n' + command + '\n';
        const out = execFileSync('bash', ['-c', script], { cwd: dir, encoding: 'utf8' });
        return { argv: out.split('\0').slice(0, -1), files: fs.readdirSync(dir) };
    } finally {
        fs.rmSync(dir, { recursive: true, force: true });
    }
}

test('values with shell metacharacters are passed literally and nothing is executed', () => {
    const request = {
        method: "POST",
        url: "http://wh.test/tok/a'$(touch PWNED_URL)'?q=`touch PWNED_Q`",
        headers: {
            'x-note': ["hi'; touch PWNED_HEADER; echo '"],
            "it's": ['$(touch PWNED_NAME)'],
            'x-multi': ['a', 'b'],
        },
        content: "{\"a\":\"line\\nbreak\"}'; touch PWNED_BODY; echo '\n$(id) \\x41",
    };

    const { argv, files } = runInShell(requestToCurl(request));

    assert.deepStrictEqual(files, [], 'an injected command was executed');
    assert.deepStrictEqual(argv, [
        '-X', 'POST',
        request.url,
        '-H', "x-note: hi'; touch PWNED_HEADER; echo '",
        '-H', "it's: $(touch PWNED_NAME)",
        '-H', 'x-multi: a,b',
        '--data-raw', request.content,
    ]);
});

test('a body starting with @ is sent as data, not read from a local file', () => {
    const { argv } = runInShell(requestToCurl({
        method: 'POST', url: 'http://wh.test/tok', headers: {}, content: '@/etc/passwd',
    }));

    assert.deepStrictEqual(argv.slice(-2), ['--data-raw', '@/etc/passwd']);
    assert.ok(!argv.includes('-d'));
});

test('requests without a body produce no data argument', () => {
    const { argv } = runInShell(requestToCurl({
        method: 'GET', url: 'http://wh.test/tok', headers: { accept: ['*/*'] }, content: '',
    }));

    assert.deepStrictEqual(argv, ['-X', 'GET', 'http://wh.test/tok', '-H', 'accept: */*']);
});

test('shellQuote handles quotes and empty strings', () => {
    assert.strictEqual(shellQuote("it's"), "'it'\\''s'");
    assert.strictEqual(shellQuote(''), "''");
});
