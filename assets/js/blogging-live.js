(() => {
  'use strict';

  const strings = window.BloggingLiveSettings?.strings || {};

  const request = async (url) => {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
      throw new Error(`Liveblog request failed with status ${response.status}`);
    }

    return response.json();
  };

  const addQuery = (endpoint, params) => {
    const url = new URL(endpoint, window.location.href);
    Object.entries(params).forEach(([key, value]) => {
      if (value) url.searchParams.set(key, value);
    });
    return url.toString();
  };

  const initialize = (container) => {
    const endpoint = container.dataset.endpoint;
    const entriesNode = container.querySelector('.blogging-live__entries');
    const newButton = container.querySelector('.blogging-live__new-updates');
    const olderButton = container.querySelector('.blogging-live__load-older');
    const message = container.querySelector('.blogging-live__message');
    const displayOrder = container.dataset.order || 'desc';
    const seen = new Set(
      Array.from(container.querySelectorAll('[data-entry-id]')).map((entry) => Number(entry.dataset.entryId)),
    );
    let pending = [];
    let busy = false;

    const removeEmptyMessage = () => {
      container.querySelector('.blogging-live__empty')?.remove();
    };

    const announcePending = () => {
      if (!pending.length) {
        newButton.hidden = true;
        return;
      }
      newButton.textContent = pending.length === 1
        ? (strings.oneNewUpdate || '1 new update')
        : (strings.manyNewUpdates || '%d new updates').replace('%d', pending.length);
      newButton.hidden = false;
    };

    const poll = async () => {
      if (busy || container.dataset.status !== 'live') return;
      busy = true;
      try {
        const data = await request(addQuery(endpoint, { after: container.dataset.latestCursor, per_page: 50 }));
        const received = data.order === 'desc' ? [...data.entries].reverse() : data.entries;
        const fresh = received.filter((entry) => !seen.has(entry.id));
        fresh.forEach((entry) => seen.add(entry.id));
        pending = pending.concat(fresh);
        if (data.newest_cursor) container.dataset.latestCursor = data.newest_cursor;
        if (!container.dataset.oldestCursor && data.oldest_cursor) container.dataset.oldestCursor = data.oldest_cursor;
        if (!container.querySelector('[data-entry-id]')) olderButton.hidden = !data.has_more;
        announcePending();
      } catch (error) {
        container.dispatchEvent(new CustomEvent('blogging-live:error', { detail: error }));
      } finally {
        busy = false;
      }
    };

    newButton.addEventListener('click', () => {
      removeEmptyMessage();
      if (displayOrder === 'desc') {
        pending.forEach((entry) => entriesNode.insertAdjacentHTML('afterbegin', entry.html));
      } else {
        pending.forEach((entry) => entriesNode.insertAdjacentHTML('beforeend', entry.html));
      }

      const addedIds = pending.map((entry) => entry.id);
      pending = [];
      announcePending();
      addedIds.forEach((id) => container.querySelector(`[data-entry-id="${id}"]`)?.classList.add('blogging-live-entry--new'));
      container.dispatchEvent(new CustomEvent('blogging-live:entries-added', { detail: { ids: addedIds } }));
    });

    olderButton.addEventListener('click', async () => {
      if (busy || !container.dataset.oldestCursor) return;
      busy = true;
      olderButton.disabled = true;
      message.textContent = strings.loading || 'Loading updates…';

      try {
        const data = await request(addQuery(endpoint, { before: container.dataset.oldestCursor }));
        const older = data.entries.filter((entry) => !seen.has(entry.id));
        older.forEach((entry) => seen.add(entry.id));
        removeEmptyMessage();

        if (displayOrder === 'asc') {
          older.reverse();
          entriesNode.insertAdjacentHTML('afterbegin', older.map((entry) => entry.html).join(''));
        } else {
          entriesNode.insertAdjacentHTML('beforeend', older.map((entry) => entry.html).join(''));
        }

        if (data.oldest_cursor) container.dataset.oldestCursor = data.oldest_cursor;
        olderButton.hidden = !data.has_more;
        message.textContent = older.length ? '' : (strings.none || 'No additional updates.');
        container.dispatchEvent(new CustomEvent('blogging-live:entries-added', { detail: { ids: older.map((entry) => entry.id) } }));
      } catch (error) {
        message.textContent = strings.error || 'Updates could not be loaded. Please try again.';
        container.dispatchEvent(new CustomEvent('blogging-live:error', { detail: error }));
      } finally {
        olderButton.disabled = false;
        busy = false;
      }
    });

    const interval = Math.max(5, Number(container.dataset.refresh || 15)) * 1000;
    if (container.dataset.status === 'live') window.setInterval(poll, interval);
  };

  document.querySelectorAll('.blogging-live[data-endpoint]').forEach(initialize);
})();
