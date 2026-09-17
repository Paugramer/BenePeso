(function () {
  'use strict';

  const form = document.getElementById('signupForm');
  if (!form) return;

  const prefix = 'benepeso_google_signup_draft_';
  const maxAgeMs = 30 * 60 * 1000;
  const fieldNames = [
    'first_name',
    'middle_name',
    'last_name',
    'ext_name',
    'birthdate',
    'sex',
    'civil_status',
    'contact_no',
    'street_purok_zone',
    'barangay',
    'district'
  ];

  function availableStorage() {
    try {
      const key = prefix + 'storage_test';
      window.localStorage.setItem(key, '1');
      window.localStorage.removeItem(key);
      return window.localStorage;
    } catch (error) {
      return null;
    }
  }

  const storage = availableStorage();
  if (!storage) return;

  function clearGoogleDrafts() {
    for (let index = storage.length - 1; index >= 0; index--) {
      const key = storage.key(index);
      if (key && key.startsWith(prefix)) storage.removeItem(key);
    }
  }

  if (form.dataset.googleSignupSuccess === '1') {
    clearGoogleDrafts();
    return;
  }

  const draftKey = form.dataset.draftKey || '';
  if (form.dataset.googleRegistration !== '1' || !draftKey.startsWith(prefix)) return;

  function readDraft() {
    try {
      const draft = JSON.parse(storage.getItem(draftKey) || 'null');
      if (!draft || typeof draft !== 'object' || Date.now() - Number(draft.savedAt || 0) > maxAgeMs) {
        storage.removeItem(draftKey);
        return null;
      }
      return draft;
    } catch (error) {
      storage.removeItem(draftKey);
      return null;
    }
  }

  function saveDraft() {
    const fields = {};
    fieldNames.forEach((name) => {
      const input = form.elements.namedItem(name);
      if (input && typeof input.value === 'string') fields[name] = input.value;
    });

    try {
      storage.setItem(draftKey, JSON.stringify({ savedAt: Date.now(), fields }));
    } catch (error) {
      // Registration still works when browser storage is full or unavailable.
    }
  }

  const draft = readDraft();
  if (draft && draft.fields && typeof draft.fields === 'object') {
    fieldNames.forEach((name) => {
      const input = form.elements.namedItem(name);
      const value = draft.fields[name];
      if (input && typeof value === 'string' && value !== '') input.value = value;
    });
    if (typeof window.calculateAge === 'function') window.calculateAge();
  }

  let saveTimer = null;
  function scheduleSave() {
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(saveDraft, 150);
  }

  fieldNames.forEach((name) => {
    const input = form.elements.namedItem(name);
    input?.addEventListener('input', scheduleSave);
    input?.addEventListener('change', scheduleSave);
  });

  window.addEventListener('pagehide', saveDraft);
})();
