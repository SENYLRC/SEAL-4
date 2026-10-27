(function () {
  var el = document.getElementById('seal-2fa-qr');
  if (!el || typeof qrcode === 'undefined') { return; }
  var qr = qrcode(0, 'M');
  qr.addData(el.getAttribute('data-uri'));
  qr.make();
  var img = new Image();
  img.src = qr.createDataURL(3, 2);
  img.alt = 'QR code for your authenticator app. If you cannot scan it, use the setup key shown below.';
  el.appendChild(img);
})();
