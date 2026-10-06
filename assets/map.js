(function () {
  var el = document.getElementById('map');
  if (!el || typeof L === 'undefined') return;
  var colors = { none: '#d32f2f', low: '#f9a825', ok: '#2e7d32' };
  var map = L.map(el).setView([32.2226, -110.9747], 9);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  }).addTo(map);
  var detail = document.getElementById('map-detail');
  function text(tag, s, cls) { var n = document.createElement(tag); n.textContent = s; if (cls) n.className = cls; return n; }

  function show(site) {
    detail.hidden = false;
    detail.replaceChildren(text('h3', site.name), text('p', site.type + (site.address ? ' · ' + site.address : ''), 'muted'), text('p', 'Loading…'));
    fetch(el.dataset.siteApi + '&site=' + site.id).then(function (r) { return r.json(); }).then(function (days) {
      detail.replaceChildren(text('h3', site.name), text('p', site.type + (site.address ? ' · ' + site.address : ''), 'muted'));
      days.forEach(function (d) {
        var h = text('h4', d.label);
        var a = document.createElement('a');
        a.href = el.dataset.siteUrl + '&site=' + site.id + '&day=' + d.date;
        a.textContent = ' Sign up for this day';
        a.className = 'button small';
        h.appendChild(a);
        var ul = document.createElement('ul');
        d.shifts.forEach(function (s) {
          var li = text('li', s.time + ' — ' + s.role + ' ');
          li.appendChild(text('span', s.label + (s.full ? ' (full)' : ''), 'badge cov-' + s.level));
          ul.appendChild(li);
        });
        detail.append(h, ul);
      });
      var all = document.createElement('a');
      all.href = el.dataset.siteUrl + '&site=' + site.id;
      all.textContent = 'See all dates and sign up';
      all.className = 'button';
      detail.appendChild(all);
      detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  }

  fetch(el.dataset.api).then(function (r) { return r.json(); }).then(function (sites) {
    var group = [];
    sites.forEach(function (s) {
      var m = L.circleMarker([s.lat, s.lng], { radius: 9, color: '#222', weight: 1, fillColor: colors[s.level] || '#888', fillOpacity: 0.9 });
      m.bindTooltip(s.name);
      m.on('click', function () { show(s); });
      m.addTo(map);
      group.push([s.lat, s.lng]);
    });
    if (group.length) map.fitBounds(group, { padding: [20, 20] });
  });
})();
