(function () {
  'use strict';

  function pluginUrl(relativePath) {
    const path = window.location.pathname || '';
    const pluginIndex = path.indexOf('/plugins/g4freportsana/');
    const frontIndex = path.indexOf('/front/');
    let base = window.GLPI_ROOT_DOC || '';
    if (pluginIndex !== -1) base = path.slice(0, pluginIndex);
    else if (frontIndex !== -1) base = path.slice(0, frontIndex);
    return window.location.origin + base.replace(/\/+$/, '') + '/plugins/g4freportsana/' + relativePath;
  }

  function readJdbc(raw) {
    const input = String(raw || '').trim();
    // Reject URL features that could carry credentials or change connection options.
    if (!/^jdbc:(mysql|mariadb):\/\//i.test(input) || /[\s\\?#%@]/.test(input)) return null;
    const address = input.replace(/^jdbc:(mysql|mariadb):\/\//i, 'http://');
    let url;
    try { url = new URL(address); } catch (error) { return null; }
    if (url.username || url.password || url.search || url.hash || !url.hostname) return null;
    if (!/^\/[A-Za-z0-9_$-]{1,64}$/.test(url.pathname)) return null;
    const authority = address.slice(7).split('/')[0];
    const rawPath = address.slice(7 + authority.length);
    if (!/^\/[A-Za-z0-9_$-]{1,64}$/.test(rawPath)) return null;
    // Require an ordinary host (or bracketed IPv6 address) and an optional numeric port.
    const authorityMatch = authority.match(/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(?::([0-9]{1,5}))?$/);
    if (!authorityMatch) return null;
    const port = authorityMatch[2] ? Number(authorityMatch[2]) : 3306;
    if (!Number.isInteger(port) || port < 1 || port > 65535) return null;
    return { host: authorityMatch[1].replace(/^\[|\]$/g, ''), port: String(port), name: rawPath.slice(1) };
  }

  function shortText(value, maxLength) {
    return typeof value === 'string' || typeof value === 'number' ? String(value).slice(0, maxLength || 500) : '';
  }

  function appendLine(parent, label, value) {
    const line = document.createElement('div');
    const title = document.createElement('strong');
    title.textContent = label + ': ';
    line.appendChild(title);
    line.appendChild(document.createTextNode(shortText(value)));
    parent.appendChild(line);
  }

  function showDetails(target, data) {
    target.replaceChildren();
    if (data.reauth_required && typeof data.reauth_url === 'string') {
      let url;
      try { url = new URL(data.reauth_url, window.location.href); } catch (_) { /* Keep the server's status message. */ }
      if (url && url.origin === window.location.origin && /^https?:$/.test(url.protocol)) {
        const link = document.createElement('a');
        link.textContent = 'Confirmar identidade no GLPI em outra aba'; link.href = url.href;
        link.target = '_blank'; link.rel = 'noopener noreferrer';
        target.append(link, document.createTextNode('. Depois volte e repita o teste; os campos desta página serão preservados.'));
      }
    }
    const connection = data.connection && typeof data.connection === 'object' ? data.connection : null;
    if (connection) {
      if (connection.database) appendLine(target, 'Banco de dados', connection.database);
      if (connection.server_version) appendLine(target, 'Versão do servidor', connection.server_version);
      if (connection.tls_mode) {
        const tls = connection.tls_mode === 'verify' ? 'TLS com verificação de certificado' : 'Sem TLS';
        appendLine(target, 'Proteção da conexão', tls + (connection.tls_cipher ? ' — ' + shortText(connection.tls_cipher, 120) : ''));
      }
    }
    if (data.schema && typeof data.schema === 'object' && !Array.isArray(data.schema)) {
      const list = document.createElement('ul');
      list.className = 'mt-2 mb-0';
      Object.keys(data.schema).slice(0, 40).forEach(function (tableName) {
        const table = data.schema[tableName];
        if (!table || typeof table !== 'object') return;
        const item = document.createElement('li');
        const requirement = table.required === false ? ' (opcional)' : '';
        item.textContent = shortText(tableName, 128) + requirement + ': ' + (shortText(table.message) || shortText(table.status, 80) || 'Verificação sem resultado.');
        list.appendChild(item);
      });
      if (list.childNodes.length) target.appendChild(list);
    }
    target.hidden = !target.childNodes.length;
  }

  function bindSection(section) {
    if (section.dataset.dbBound === '1') return;
    section.dataset.dbBound = '1';
    const editable = section.dataset.dbEditable === '1';
    const prefix = section.dataset.dbPrefix;
    const source = section.dataset.dbSource;
    if (source !== 'glpi' && source !== 'callcenter') return;
    const testButton = section.querySelector('[data-db-test]');
    const pill = section.querySelector('[data-db-pill]');
    const status = section.querySelector('[data-db-status]');
    const details = section.querySelector('[data-db-result]');
    const jdbc = section.querySelector('[data-db-jdbc]');
    const jdbcMessage = section.querySelector('[data-db-jdbc-message]');
    const applyButton = section.querySelector('[data-db-apply]');
    let dirty = false;
    let busy = false;
    let requestController = null;

    function setState(state, label, message) {
      pill.dataset.state = state;
      pill.textContent = label;
      status.textContent = message || '';
    }

    function markDirty() {
      dirty = true;
      testButton.disabled = true;
      if (requestController) requestController.abort();
      details.hidden = true;
      details.replaceChildren();
      setState('unknown', 'Não salva', 'Conexão alterada. Salve as configurações para testar.');
    }

    section.querySelectorAll('input[name], select[name]').forEach(function (field) {
      field.addEventListener('input', markDirty);
      field.addEventListener('change', markDirty);
    });

    applyButton.addEventListener('click', function () {
      if (!editable) return;
      const parsed = readJdbc(jdbc.value);
      if (!parsed) {
        jdbcMessage.textContent = 'Informe jdbc:mysql://servidor:porta/banco ou jdbc:mariadb://servidor:porta/banco, sem usuário, senha ou parâmetros adicionais.';
        return;
      }
      Object.keys(parsed).forEach(function (key) {
        const field = section.querySelector('[name="' + prefix + key + '"]');
        if (field) field.value = parsed[key];
      });
      jdbc.value = '';
      jdbcMessage.textContent = 'Servidor, porta e banco preenchidos. Complete usuário e senha e salve as configurações.';
      markDirty();
    });

    testButton.addEventListener('click', async function () {
      if (!editable || busy || dirty) return;
      busy = true;
      testButton.disabled = true;
      details.hidden = true;
      details.replaceChildren();
      setState('busy', 'Testando…', 'Verificando a conexão salva e o acesso às tabelas.');
      requestController = new AbortController();
      const timer = window.setTimeout(function () { requestController.abort(); }, 45000);
      try {
        // Connection secrets and unsaved form values must never be put in this URL.
        const url = pluginUrl('front/test_database.php') + '?source=' + encodeURIComponent(source);
        const response = await fetch(url, {
          method: 'GET', credentials: 'same-origin', cache: 'no-store',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          signal: requestController.signal
        });
        let data = null;
        try { data = await response.json(); } catch (error) { /* Never display an HTML login/error response. */ }
        if (dirty) return;
        if (!data || typeof data !== 'object' || Array.isArray(data)) {
          const message = response.status === 401 || response.status === 403
            ? 'Sessão expirada ou acesso não autorizado. Recarregue a página e tente novamente.'
            : 'Não foi possível obter o resultado do teste. Recarregue a página e tente novamente.';
          setState('fail', 'Falhou', message);
          return;
        }
        const ok = response.ok && data.ok === true;
        setState(ok ? 'ok' : 'fail', ok ? 'Conectada' : 'Verificar', shortText(data.message) || (ok ? 'Conexão e acesso verificados.' : 'Não foi possível validar a conexão. Confira as configurações salvas.'));
        showDetails(details, data);
      } catch (error) {
        if (!dirty) {
          setState('fail', 'Falhou', error && error.name === 'AbortError'
            ? 'O teste excedeu o tempo limite. Verifique o acesso ao banco a partir do servidor GLPI.'
            : 'Não foi possível concluir o teste. Verifique sua conexão com o GLPI e tente novamente.');
        }
      } finally {
        window.clearTimeout(timer);
        requestController = null;
        busy = false;
        testButton.disabled = !editable || dirty;
      }
    });
  }

  function initialize() {
    document.querySelectorAll('.g4fr-database-connection').forEach(bindSection);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
})();
