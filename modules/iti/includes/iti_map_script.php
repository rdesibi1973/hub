<?php
/**
 * modules/iti/includes/iti_map_script.php
 * Shared Leaflet builder for the itinerary map. Include INSIDE a <script> tag,
 * with $map (from iti_get_program_map()) in scope and Leaflet already loaded.
 * Renders numbered red pills for stops (overlapping stops merge into "2 & 4")
 * and slate ✈ pins for the arrival/departure airports.
 */
?>
(function(){
  var route   = <?= json_encode($map['route'],   JSON_UNESCAPED_UNICODE) ?>;
  var markers = <?= json_encode($map['markers'], JSON_UNESCAPED_UNICODE) ?>;
  if (!route.length || typeof L === 'undefined') return;
  var map = L.map('itiMap', { scrollWheelZoom:false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 17, attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  // Route line follows every point in order (so an out-and-back leg is drawn).
  var latlngs = route.map(function(p){ return [p.lat, p.lng]; });

  markers.forEach(function(g){
    var icon, title;
    if (g.airport) {
      icon = L.divIcon({ className:'iti-marker',
        html:'<div style="background:#1F5673;color:#fff;width:30px;height:30px;'
            +'border-radius:50%;display:flex;align-items:center;justify-content:center;'
            +'font-size:15px;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4);">&#9992;</div>',
        iconSize:[30,30], iconAnchor:[15,15] });
      title = (g.name || '');
    } else {
      var w = Math.max(26, 15 + g.label.length * 7);
      icon = L.divIcon({ className:'iti-marker',
        html:'<div style="background:#C0211B;color:#fff;height:26px;padding:0 7px;'
            +'box-sizing:border-box;border-radius:13px;display:flex;align-items:center;'
            +'justify-content:center;font:700 12px/1 sans-serif;border:2px solid #fff;'
            +'box-shadow:0 1px 4px rgba(0,0,0,.4);white-space:nowrap;">' + g.label + '</div>',
        iconSize:[w,26], iconAnchor:[w/2,13] });
      title = g.label + '. ' + (g.name || '');
    }
    L.marker([g.lat, g.lng], { icon: icon }).addTo(map)
     .bindPopup('<strong>' + title + '</strong>');
  });

  if (latlngs.length > 1) {
    L.polyline(latlngs, { color:'#C0211B', weight:3, opacity:.75, dashArray:'6,6' }).addTo(map);
    map.fitBounds(L.latLngBounds(latlngs).pad(0.2));
  } else {
    map.setView(latlngs[0], 8);
  }
})();
