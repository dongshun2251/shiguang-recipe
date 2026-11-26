<?php
/**
 * 扫码识别 Worker：
 * 把 jsQR 的解码放到独立线程，主线程只负责取一帧画面，
 * 避免识别时阻塞相机预览，导致录屏画面越来越滞后。
 */
$file = __DIR__ . '/assets/jsQR.js';
if (!is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
header('Content-Type: application/javascript; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: "' . sha1('worker|' . filemtime($file) . '|' . filesize($file)) . '"');
readfile($file);
?>
self.onmessage = function (e) {
  var d = e.data || {};
  try {
    var code = self.jsQR(new Uint8ClampedArray(d.buf), d.w, d.h, {
      inversionAttempts: d.inv || 'dontInvert'
    });
    self.postMessage({ code: code && code.data ? code.data : '' });
  } catch (err) {
    self.postMessage({ code: '', error: String(err) });
  }
};
