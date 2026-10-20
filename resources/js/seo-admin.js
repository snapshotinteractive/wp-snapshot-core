const config = window.snapSeo || {};
const i18n = config.i18n || {};

const el = (tag, attrs = {}, children = []) => {
  const node = document.createElement(tag);

  Object.entries(attrs).forEach(([key, value]) => {
    if (value === null || value === undefined || value === false) {
      return;
    }

    if (key === 'class') {
      node.className = value;
    } else if (key === 'text') {
      node.textContent = value;
    } else {
      node.setAttribute(key, value === true ? '' : value);
    }
  });

  (Array.isArray(children) ? children : [children]).filter(Boolean).forEach((child) => {
    node.append(typeof child === 'string' ? document.createTextNode(child) : child);
  });

  return node;
};

const debounce = (fn, wait = 400) => {
  let timer;

  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), wait);
  };
};

const post = async (url, body) => {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.rest?.nonce || '' },
    body: JSON.stringify(body),
  });

  if (!response.ok) {
    throw new Error(`Request failed: ${response.status}`);
  }

  return response.json();
};

const format = (template, value) => String(template || '').replace('%s', value).replace('%d', value);

const dialog = document.getElementById('snap-seo-help');

const openHelp = (topic, trigger) => {
  const entry = config.help?.[topic];

  if (!dialog || !entry || typeof dialog.showModal !== 'function') {
    return;
  }

  dialog.querySelector('[data-snap-help-title]').textContent = entry.title;
  const body = dialog.querySelector('[data-snap-help-body]');
  body.replaceChildren();

  [['what', i18n.what], ['why', i18n.whyHeading], ['do', i18n.do]].forEach(([key, heading]) => {
    if (entry[key]) {
      body.append(el('h3', { text: heading }), el('p', { text: entry[key] }));
    }
  });

  if (entry.auto) {
    body.append(el('div', { class: 'snap-help-auto' }, [el('h3', { text: i18n.auto }), el('p', { text: entry.auto })]));
  }

  dialog.returnFocusTo = trigger;
  dialog.showModal();
};

if (dialog) {
  dialog.addEventListener('close', () => dialog.returnFocusTo?.focus());
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
      dialog.close();
    }
  });
}

document.addEventListener('click', (event) => {
  const trigger = event.target.closest('[data-snap-help]');

  if (trigger) {
    event.preventDefault();
    openHelp(trigger.dataset.snapHelp, trigger);
  }
});

const helpButton = (topic, label) => {
  const button = el('button', { type: 'button', class: 'snap-help', 'data-snap-help': topic }, [
    el('span', { class: 'dashicons dashicons-editor-help', 'aria-hidden': 'true' }),
    el('span', { class: 'screen-reader-text', text: `${i18n.why}: ${label}` }),
  ]);

  return button;
};

document.querySelectorAll('[data-snap-master], input[data-confirm-off]').forEach((input) => {
  input.addEventListener('change', () => {
    if (!input.checked && input.dataset.confirmOff && !window.confirm(input.dataset.confirmOff)) {
      input.checked = true;
    }

    const grid = document.querySelector('[data-snap-module-grid]');

    if (input.hasAttribute('data-snap-master') && grid) {
      grid.classList.toggle('is-master-off', !input.checked);
    }
  });
});

document.querySelectorAll('button[data-confirm]').forEach((button) => {
  button.addEventListener('click', (event) => {
    if (!window.confirm(button.dataset.confirm)) {
      event.preventDefault();
    }
  });
});

document.querySelectorAll('[data-snap-check-all]').forEach((toggle) => {
  toggle.addEventListener('change', () => {
    toggle.closest('table').querySelectorAll('tbody input[type="checkbox"]').forEach((box) => {
      box.checked = toggle.checked;
    });
  });
});

const counter = (input, limit) => {
  if (!input || input.dataset.snapCounted) {
    return;
  }

  input.dataset.snapCounted = '1';
  const output = el('span', { class: 'snap-count', 'aria-live': 'polite' });
  input.insertAdjacentElement('afterend', output);

  const update = () => {
    const length = input.value.length;
    output.textContent = length ? format(i18n.chars, length) : '';
    output.classList.toggle('is-over', length > limit);
    output.classList.toggle('is-good', length > 0 && length <= limit && length >= limit * 0.6);
  };

  input.addEventListener('input', update);
  update();
};

document.querySelectorAll('[data-snap-count]').forEach((input) => counter(input, Number(input.dataset.snapCount)));

const insertAtCursor = (input, text) => {
  if (!input) {
    return;
  }

  const start = input.selectionStart ?? input.value.length;
  const end = input.selectionEnd ?? input.value.length;
  input.value = input.value.slice(0, start) + text + input.value.slice(end);
  const caret = text.endsWith(':%%') ? start + text.length - 2 : start + text.length;
  input.focus();
  input.setSelectionRange(caret, caret);
  input.dispatchEvent(new Event('input', { bubbles: true }));
};

let lastTemplate = null;

document.querySelectorAll('[data-snap-template] input').forEach((input) => {
  const wrapper = input.closest('[data-snap-template]');
  const sample = wrapper.querySelector('[data-snap-sample]');

  input.addEventListener('focus', () => {
    lastTemplate = input;
  });

  const refresh = debounce(async () => {
    if (!config.rest?.template || !wrapper.dataset.scope) {
      return;
    }

    try {
      const result = await post(config.rest.template, {
        template: input.value.trim() || input.dataset.default || '',
        scope: wrapper.dataset.scope,
        field: wrapper.dataset.field,
      });

      sample.replaceChildren();

      if (result.output) {
        sample.append(
          el('span', { class: 'snap-template-sample-label', text: result.sample ? format(i18n.example, result.sample) : '' }),
          el('span', { text: result.output }),
        );
      }
    } catch (error) {
      sample.textContent = '';
    }
  });

  input.addEventListener('input', refresh);
});

document.querySelectorAll('[data-snap-variable]').forEach((button) => {
  button.addEventListener('mousedown', (event) => event.preventDefault());
  button.addEventListener('click', () => {
    const name = button.dataset.snapVariable;
    const target = lastTemplate || document.querySelector('[data-snap-template] input');
    insertAtCursor(target, `%%${name}%%`);
  });
});

document.querySelectorAll('[data-snap-image]').forEach((wrapper) => {
  const input = wrapper.querySelector('[data-snap-image-input]');
  const preview = wrapper.querySelector('[data-snap-image-preview]');
  const clear = wrapper.querySelector('[data-snap-image-clear]');
  let frame;

  wrapper.querySelector('[data-snap-image-choose]')?.addEventListener('click', () => {
    if (!window.wp?.media) {
      return;
    }

    frame = frame || window.wp.media({ library: { type: 'image' }, multiple: false });
    frame.on('select', () => {
      const attachment = frame.state().get('selection').first().toJSON();
      input.value = attachment.id;
      const url = attachment.sizes?.medium?.url || attachment.url;
      preview.replaceChildren(el('img', { src: url, alt: '' }));
      clear.hidden = false;
    });
    frame.open();
  });

  clear?.addEventListener('click', () => {
    input.value = '';
    preview.replaceChildren();
    clear.hidden = true;
  });
});

const editorContext = config.context;

const acfInput = (name) => document.querySelector(`.acf-field[data-name="${name}"] input:not([type="hidden"]), .acf-field[data-name="${name}"] textarea, .acf-field[data-name="${name}"] select`);

const acfImage = (name) => document.querySelector(`.acf-field[data-name="${name}"] input[type="hidden"]`);

const blockEditor = () => window.wp?.data?.select?.('core/editor');

const liveTitle = () => {
  if (editorContext?.kind === 'term') {
    return document.querySelector('#name')?.value || '';
  }

  return blockEditor()?.getEditedPostAttribute?.('title') ?? document.querySelector('#title')?.value ?? '';
};

const liveExcerpt = () => blockEditor()?.getEditedPostAttribute?.('excerpt') ?? document.querySelector('#excerpt')?.value;

const liveSlug = () => blockEditor()?.getEditedPostAttribute?.('slug') || document.querySelector('#post_name')?.value || '';

const statusIcon = { good: 'yes-alt', warn: 'warning', bad: 'dismiss', info: 'info-outline' };

const renderSerp = (data) => {
  const target = document.querySelector('[data-snap-seo-preview]');

  if (!target) {
    return;
  }

  const meta = el('div', { class: 'snap-seo-meta' }, [
    el('span', { class: `snap-pill ${data.robots.index ? 'is-ok' : 'is-warning'}`, text: data.robots.index ? data.schema_type : `${i18n.hidden}: ${data.robots.reason}` }),
    el('span', { class: 'snap-pill is-info', text: `${data.title_source === 'custom' ? i18n.custom : i18n.template}: ${format(i18n.chars, data.title.length)}` }),
    el('span', { class: 'snap-pill is-info', text: `${data.description_source === 'custom' ? i18n.custom : i18n.template}: ${format(i18n.chars, data.description.length)}` }),
  ]);

  target.replaceChildren(
    el('div', { class: 'snap-seo-serp' }, [
      el('div', { class: 'snap-seo-serp-site' }, [
        el('span', {}, [
          el('span', { class: 'snap-seo-serp-name', text: data.social.host }),
          el('span', { class: 'snap-seo-serp-url', text: data.display_url }),
        ]),
      ]),
      el('span', { class: 'snap-seo-serp-title', text: data.title.length > 62 ? `${data.title.slice(0, 60)}…` : data.title }),
      el('p', { class: 'snap-seo-serp-description', text: data.description.length > 158 ? `${data.description.slice(0, 155)}…` : data.description }),
    ]),
    meta,
  );
};

const renderSocial = (data) => {
  const target = document.querySelector('[data-snap-seo-social]');

  if (!target) {
    return;
  }

  target.replaceChildren(
    el('div', { class: 'snap-seo-social' }, [
      el('div', { class: 'snap-seo-social-image' }, data.social.image ? el('img', { src: data.social.image, alt: '' }) : null),
      el('div', { class: 'snap-seo-social-text' }, [
        el('span', { class: 'snap-seo-social-host', text: data.social.host }),
        el('span', { class: 'snap-seo-social-title', text: data.social.title }),
        el('p', { class: 'snap-seo-social-description', text: data.social.description }),
      ]),
    ]),
  );
};

const renderChecks = (data) => {
  const target = document.querySelector('[data-snap-seo-checks]');

  if (!target || !Array.isArray(data.checks)) {
    return;
  }

  target.replaceChildren(
    el('ul', { class: 'snap-seo-checks' }, data.checks.map((check) => {
      const item = el('li', { class: `is-${check.status}` }, [
        el('span', { class: `dashicons dashicons-${statusIcon[check.status] || 'info-outline'}`, 'aria-hidden': 'true' }),
        el('span', { text: check.text }),
      ]);

      if (check.topic && config.help?.[check.topic]) {
        item.append(helpButton(check.topic, check.text));
      }

      return item;
    })),
  );
};

const collect = () => {
  const fields = { post_title: liveTitle(), post_name: liveSlug() };
  const excerpt = liveExcerpt();

  if (typeof excerpt === 'string') {
    fields.post_excerpt = excerpt;
  }

  ['seo_title', 'seo_description', 'seo_focus_keyphrase', 'seo_social_title', 'seo_social_description', 'seo_robots', 'seo_schema_type', 'seo_canonical'].forEach((name) => {
    const input = acfInput(name);

    if (input) {
      fields[name] = input.value;
    }
  });

  const nofollow = document.querySelector('.acf-field[data-name="seo_nofollow"] input[type="checkbox"]');

  if (nofollow) {
    fields.seo_nofollow = nofollow.checked ? '1' : '';
  }

  const image = acfImage('seo_social_image');

  if (image) {
    fields.seo_social_image = image.value;
  }

  return fields;
};

const refreshPreview = debounce(async () => {
  if (!editorContext || !config.rest?.preview) {
    return;
  }

  const targets = document.querySelectorAll('[data-snap-seo-preview], [data-snap-seo-social], [data-snap-seo-checks]');
  targets.forEach((target) => target.classList.add('snap-seo-loading'));

  try {
    const data = await post(config.rest.preview, { id: editorContext.id, kind: editorContext.kind, fields: collect() });

    if (!data.error) {
      renderSerp(data);
      renderSocial(data);
      renderChecks(data);
    }
  } catch (error) {
    targets.forEach((target) => target.classList.remove('snap-seo-loading'));
  } finally {
    targets.forEach((target) => target.classList.remove('snap-seo-loading'));
  }
}, 500);

const chips = (input) => {
  const wrapper = el('div', { class: 'snap-seo-chips', role: 'group', 'aria-label': i18n.insert });

  ['title', 'sep', 'sitename', 'excerpt', 'primary_term', 'pt_single'].forEach((name) => {
    const chip = el('button', { type: 'button', text: `%%${name}%%` });
    chip.addEventListener('mousedown', (event) => event.preventDefault());
    chip.addEventListener('click', () => insertAtCursor(input, `%%${name}%%`));
    wrapper.append(chip);
  });

  input.insertAdjacentElement('afterend', wrapper);
};

const bootEditor = () => {
  if (!editorContext || !document.querySelector('[data-snap-seo-preview]')) {
    return;
  }

  Object.entries(config.fieldTopics || {}).forEach(([name, topic]) => {
    const label = document.querySelector(`.acf-field[data-name="${name}"] .acf-label label`);

    if (label && !label.parentElement.querySelector('.snap-help')) {
      label.insertAdjacentElement('afterend', helpButton(topic, label.textContent.trim()));
    }
  });

  [['seo_title', 60], ['seo_description', 155], ['seo_social_title', 60]].forEach(([name, limit]) => {
    const input = acfInput(name);

    if (input) {
      counter(input, limit);

      if (name !== 'seo_social_title') {
        chips(input);
      }
    }
  });

  document.addEventListener('input', (event) => {
    if (event.target.closest('[id="acf-group_snapshot_seo"], [id="acf-group_snapshot_seo_term"], .acf-field[data-name^="seo_"], #title, #name, #excerpt, #description')) {
      refreshPreview();
    }
  });

  document.addEventListener('change', (event) => {
    if (event.target.closest('.acf-field[data-name^="seo_"]')) {
      refreshPreview();
    }
  });

  if (window.acf?.addAction) {
    window.acf.addAction('change', refreshPreview);
  }

  if (window.wp?.data?.subscribe && blockEditor()) {
    let previous = `${liveTitle()}|${liveSlug()}|${liveExcerpt()}`;

    window.wp.data.subscribe(() => {
      const current = `${liveTitle()}|${liveSlug()}|${liveExcerpt()}`;

      if (current !== previous) {
        previous = current;
        refreshPreview();
      }
    });
  }

  refreshPreview();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootEditor);
} else {
  bootEditor();
}

document.querySelectorAll('[data-snap-color]').forEach((wrapper) => {
  const picker = wrapper.querySelector('[data-snap-color-picker]');
  const text = wrapper.querySelector('[data-snap-color-value]');

  if (!picker || !text) {
    return;
  }

  picker.addEventListener('input', () => {
    text.value = picker.value;
  });

  text.addEventListener('input', () => {
    if (/^#[\da-f]{6}$/i.test(text.value)) {
      picker.value = text.value;
    }
  });

  wrapper.querySelector('[data-snap-color-clear]')?.addEventListener('click', () => {
    text.value = '';
    text.focus();
  });
});
