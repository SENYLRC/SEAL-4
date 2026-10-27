/**
 * Security keys and passkeys (WebAuthn) for the ILL two-factor screens. No build step, no jQuery.
 *   [data-webauthn-register]  form on My Profile: adds a key (password re-check, then touch)
 *   [data-webauthn-login]     button on the second sign-in step: signs in with a touch
 * Progress and errors go to the nearest [data-webauthn-status] (role="status", announced by screen readers).
 * Ported from Argus (docs/yubikey-passkey-two-step-guide.md). The server library (lbuchs/webauthn) sends binary
 * values as "=?BINARY?B?<base64>?=" strings; toBuffers() turns them back into ArrayBuffers.
 */
(function () {
  'use strict';

  function toBuffers(obj) {
    if (typeof obj === 'string') {
      var m = obj.match(/^=\?BINARY\?B\?(.*)\?=$/);
      if (!m) { return obj; }
      var bin = atob(m[1]), buf = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) { buf[i] = bin.charCodeAt(i); }
      return buf.buffer;
    }
    if (Array.isArray(obj)) { return obj.map(toBuffers); }
    if (obj && typeof obj === 'object') {
      var out = {};
      Object.keys(obj).forEach(function (k) { out[k] = toBuffers(obj[k]); });
      return out;
    }
    return obj;
  }

  function b64(buffer) {
    var bytes = new Uint8Array(buffer), s = '';
    for (var i = 0; i < bytes.length; i++) { s += String.fromCharCode(bytes[i]); }
    return btoa(s);
  }

  /** POST as a form; always resolves to an object ({ok:false, error} on network or server trouble). */
  function post(url, fields) {
    var fd = new FormData();
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'The server could not be reached. Please try again.' }; });
  }

  /** Plain-language versions of the browser's WebAuthn errors. */
  function explain(err) {
    var n = err && err.name;
    if (n === 'NotAllowedError') { return 'Cancelled or timed out. Try again and touch your key when it blinks.'; }
    if (n === 'InvalidStateError') { return 'That key is already registered to your account.'; }
    if (n === 'SecurityError') { return 'Security keys only work on the site’s normal https address.'; }
    if (n === 'NotSupportedError') { return 'This browser or device does not support that kind of key.'; }
    return (err && err.message) ? err.message : 'Something went wrong. Please try again.';
  }

  function say(el, msg) { if (el) { el.textContent = msg; } }

  var supported = !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);

  // ── My Profile: add a key ────────────────────────────────────────────────
  Array.prototype.forEach.call(document.querySelectorAll('[data-webauthn-register]'), function (form) {
    var status = form.querySelector('[data-webauthn-status]');
    var btn = form.querySelector('[data-webauthn-add]');
    if (!supported) {
      if (btn) { btn.disabled = true; }
      say(status, 'This browser can’t use security keys or passkeys. Try a current version of Chrome, Edge, Firefox or Safari.');
      return;
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var url = form.getAttribute('data-url'), nonce = form.getAttribute('data-nonce');
      var kindEl = form.querySelector('input[name="kind"]:checked');
      var kind = kindEl ? kindEl.value : 'key';
      var name = form.querySelector('[name="name"]').value;
      var pw = form.querySelector('[name="password"]').value;
      if (!pw) { say(status, 'Enter your current password first.'); return; }
      btn.disabled = true;
      say(status, kind === 'passkey' ? 'Follow your computer’s or phone’s prompt…' : 'Insert your key and touch it when it blinks…');
      post(url, { action: 'seal_2fa_key_options', nonce: nonce, password: pw, kind: kind })
        .then(function (r) {
          if (!r.ok) { throw new Error(r.error || 'Could not start.'); }
          return navigator.credentials.create(toBuffers(r.options));
        })
        .then(function (cred) {
          say(status, 'Checking the key…');
          return post(url, { action: 'seal_2fa_key_add', nonce: nonce, name: name, kind: kind,
            clientDataJSON: b64(cred.response.clientDataJSON),
            attestationObject: b64(cred.response.attestationObject) });
        })
        .then(function (r) {
          if (!r.ok) { throw new Error(r.error || 'The key could not be added.'); }
          say(status, 'Key added. Reloading…');
          window.location.assign(r.redirect);   // carries a throwaway ?k= so the page really reloads
        })
        .catch(function (err) { say(status, explain(err)); btn.disabled = false; });
    });
  });

  // ── Second sign-in step: use a key ───────────────────────────────────────
  Array.prototype.forEach.call(document.querySelectorAll('[data-webauthn-login]'), function (btn) {
    var status = (btn.closest('form') || btn.parentNode).querySelector('[data-webauthn-status]');
    if (!supported || !navigator.credentials.get) {
      btn.disabled = true;
      say(status, 'This browser can’t use security keys. Enter a code below instead.');
      return;
    }
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-url');
      var base = { uid: btn.getAttribute('data-uid'), key: btn.getAttribute('data-key') };
      btn.disabled = true;
      say(status, 'Touch your security key, or follow your device’s prompt…');
      post(url, Object.assign({ op: 'options' }, base))
        .then(function (r) {
          if (r.redirect) { window.location.href = r.redirect; return null; }
          if (!r.ok) { throw new Error(r.error || 'Could not start.'); }
          return navigator.credentials.get(toBuffers(r.options));
        })
        .then(function (cred) {
          if (!cred) { return null; }
          say(status, 'Checking…');
          return post(url, Object.assign({ op: 'verify',
            redirect_to: btn.getAttribute('data-redirect') || '',
            remember: btn.getAttribute('data-remember') || '',
            credentialId: b64(cred.rawId),
            clientDataJSON: b64(cred.response.clientDataJSON),
            authenticatorData: b64(cred.response.authenticatorData),
            signature: b64(cred.response.signature) }, base));
        })
        .then(function (r) {
          if (!r) { return; }
          if (r.redirect) { say(status, r.ok ? 'Signed in. Loading…' : 'Please sign in again.'); window.location.href = r.redirect; return; }
          if (!r.ok) { throw new Error(r.error || 'That key was not accepted.'); }
        })
        .catch(function (err) { say(status, explain(err)); btn.disabled = false; });
    });
  });
})();
