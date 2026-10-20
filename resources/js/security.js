const toBuffer = (value) => {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
  const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4);
  const binary = atob(padded);
  const bytes = new Uint8Array(binary.length);

  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }

  return bytes.buffer;
};

const toBase64Url = (buffer) => {
  const bytes = new Uint8Array(buffer);
  let binary = '';

  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i]);
  }

  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

const passkeysSupported = () => typeof window.PublicKeyCredential === 'function' && !!navigator.credentials;

const creationOptions = (options) => {
  const publicKey = { ...options.publicKey };
  publicKey.challenge = toBuffer(publicKey.challenge);
  publicKey.user = { ...publicKey.user, id: toBuffer(publicKey.user.id) };
  publicKey.excludeCredentials = (publicKey.excludeCredentials || []).map((item) => ({ ...item, id: toBuffer(item.id) }));

  return { publicKey };
};

const requestOptions = (options) => {
  const publicKey = { ...options.publicKey };
  publicKey.challenge = toBuffer(publicKey.challenge);
  publicKey.allowCredentials = (publicKey.allowCredentials || []).map((item) => ({ ...item, id: toBuffer(item.id) }));

  return { publicKey };
};

const serializeAttestation = (credential) => JSON.stringify({
  id: toBase64Url(credential.rawId),
  clientDataJSON: toBase64Url(credential.response.clientDataJSON),
  attestationObject: toBase64Url(credential.response.attestationObject),
});

const serializeAssertion = (credential) => JSON.stringify({
  id: toBase64Url(credential.rawId),
  clientDataJSON: toBase64Url(credential.response.clientDataJSON),
  authenticatorData: toBase64Url(credential.response.authenticatorData),
  signature: toBase64Url(credential.response.signature),
});

const say = (scope, message) => {
  const status = scope.querySelector('[data-snap-passkey-status]');

  if (status) {
    status.textContent = message;
    status.hidden = !message;
  }
};

const explain = (error) => {
  if (error && error.name === 'NotAllowedError') {
    return 'The passkey request was canceled or timed out. Try again when you are ready.';
  }

  if (error && error.name === 'InvalidStateError') {
    return 'This device already has a passkey for your account.';
  }

  return 'Your browser could not use a passkey here. Use another method.';
};

const readOptions = (button) => {
  try {
    return JSON.parse(button.dataset.options || 'null');
  } catch {
    return null;
  }
};

const ceremony = (button, run) => {
  const form = button.closest('form');

  if (!passkeysSupported()) {
    if (form && form.hasAttribute('data-snap-passkey-only')) {
      form.hidden = true;
    } else {
      button.disabled = true;
      say(form || document, 'This browser does not support passkeys.');
    }

    return;
  }

  button.addEventListener('click', async () => {
    const options = readOptions(button);

    if (!options || !form) {
      return;
    }

    button.disabled = true;
    say(form, 'Waiting for your passkey…');

    try {
      form.querySelector('[data-snap-credential]').value = await run(options);
      form.submit();
    } catch (error) {
      button.disabled = false;
      say(form, explain(error));
    }
  });
};

document.querySelectorAll('[data-snap-passkey-auth]').forEach((button) => {
  ceremony(button, async (options) => serializeAssertion(await navigator.credentials.get(requestOptions(options))));
});

document.querySelectorAll('[data-snap-passkey-enroll]').forEach((button) => {
  ceremony(button, async (options) => serializeAttestation(await navigator.credentials.create(creationOptions(options))));
});

const profile = document.querySelector('[data-snap-profile]');

if (profile) {
  const form = profile.closest('form');
  const opField = form ? form.querySelector('[data-snap-op-field]') : null;

  const runOp = (op, confirmText) => {
    if (!form || !opField || (confirmText && !window.confirm(confirmText))) {
      return;
    }

    opField.value = op;

    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  };

  profile.querySelectorAll('[data-snap-op]').forEach((button) => {
    button.addEventListener('click', () => runOp(button.dataset.snapOp, button.dataset.confirm));
  });

  profile.querySelectorAll('[data-snap-enter]').forEach((input) => {
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        runOp(input.dataset.snapEnter);
      }
    });
  });

  const addButton = profile.querySelector('[data-snap-passkey-add]');

  if (addButton) {
    if (!passkeysSupported()) {
      addButton.disabled = true;
      say(profile, 'This browser does not support passkeys.');
    }

    addButton.addEventListener('click', async () => {
      const post = (body) => fetch(profile.dataset.ajax, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ nonce: profile.dataset.nonce, ...body }),
      }).then((response) => response.json());

      addButton.disabled = true;
      say(profile, 'Waiting for your passkey…');

      try {
        const options = await post({ action: 'snapshot_2fa_passkey_options' });

        if (!options.success) {
          throw new Error('options');
        }

        const credential = await navigator.credentials.create(creationOptions(options.data));
        const nameField = profile.querySelector('[data-snap-passkey-name]');
        const result = await post({
          action: 'snapshot_2fa_passkey_register',
          credential: serializeAttestation(credential),
          name: nameField ? nameField.value : '',
        });

        if (!result.success) {
          throw new Error((result.data && result.data.message) || 'register');
        }

        say(profile, 'Passkey added. Reloading…');
        window.location.reload();
      } catch (error) {
        addButton.disabled = false;
        const known = error instanceof Error && !['options', 'register'].includes(error.message) && !(error instanceof DOMException);
        say(profile, known ? error.message : explain(error));
      }
    });
  }
}

const codesText = () => Array.from(document.querySelectorAll('[data-snap-codes] code')).map((code) => code.textContent.trim()).join('\n');

document.querySelectorAll('[data-snap-copy-codes]').forEach((button) => {
  button.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(codesText());
      button.textContent = 'Copied';
    } catch {
      button.textContent = 'Select and copy the codes';
    }
  });
});

document.querySelectorAll('[data-snap-download-codes]').forEach((button) => {
  button.addEventListener('click', () => {
    const blob = new Blob([`${document.title}\nRecovery codes, each works once.\n\n${codesText()}\n`], { type: 'text/plain' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = button.dataset.filename || 'recovery-codes.txt';
    document.body.append(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(link.href);
  });
});

document.querySelectorAll('form[data-snap-confirm]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.snapConfirm)) {
      event.preventDefault();
    }
  });
});

document.querySelectorAll('form[data-snap-modules]').forEach((form) => {
  const master = form.querySelector('[data-snap-master]');
  const grid = form.querySelector('[data-snap-module-grid]');

  master?.addEventListener('change', () => {
    grid?.classList.toggle('is-master-off', !master.checked);
  });

  form.addEventListener('submit', (event) => {
    const preset = event.submitter?.dataset.confirm;

    if (preset) {
      if (!window.confirm(preset)) {
        event.preventDefault();
      }

      return;
    }

    const switchedOff = Array.from(form.querySelectorAll('input[data-confirm-off]'))
      .filter((input) => !input.disabled && input.defaultChecked && !input.checked);

    for (const input of switchedOff) {
      if (!window.confirm(input.dataset.confirmOff)) {
        event.preventDefault();

        return;
      }
    }
  });
});
