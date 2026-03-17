import React, { useState } from 'react';
import QRCode from 'qrcode';
import { QrCode } from 'lucide-react';

const CANVAS_WIDTH = 600;
const PADDING = 48;
const INNER_WIDTH = CANVAS_WIDTH - PADDING * 2;

function wrapText(ctx, text, maxWidth) {
  const words = text.split(' ');
  const lines = [];
  let current = '';
  for (const word of words) {
    const test = current ? current + ' ' + word : word;
    if (ctx.measureText(test).width > maxWidth && current) {
      lines.push(current);
      current = word;
    } else {
      current = test;
    }
  }
  if (current) lines.push(current);
  return lines;
}

async function loadImage(src) {
  return new Promise((resolve) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => resolve(img);
    img.onerror = () => resolve(null);
    img.src = src;
  });
}

async function generateQRImage(page, siteUrl) {
  const pageUrl = `${siteUrl}/${page.url_slug}`;
  const bgColor = page.background_color || '#ffffff';
  const textColor = page.text_color || '#000000';

  // 1. Generate QR as data URL
  const qrDataUrl = await QRCode.toDataURL(pageUrl, {
    width: 200,
    margin: 1,
    color: { dark: textColor, light: bgColor },
  });
  const qrImg = await loadImage(qrDataUrl);

  // 2. Load profile image if exists
  let profileImg = null;
  if (page.profile_image) {
    profileImg = await loadImage(page.profile_image);
  }

  // 3. Calculate canvas height dynamically
  const tempCanvas = document.createElement('canvas');
  tempCanvas.width = CANVAS_WIDTH;
  tempCanvas.height = 100;
  const tempCtx = tempCanvas.getContext('2d');

  // Measure title lines
  tempCtx.font = 'bold 30px system-ui, sans-serif';
  const titleLines = wrapText(tempCtx, page.title || '', INNER_WIDTH);

  // Measure description lines (max 4)
  tempCtx.font = '18px system-ui, sans-serif';
  const descLines = page.description
    ? wrapText(tempCtx, page.description, INNER_WIDTH).slice(0, 4)
    : [];

  const profileH = profileImg ? 110 + 24 : 0;      // image + gap
  const titleH = titleLines.length * 38;             // ~38px per line
  const descH = descLines.length > 0 ? descLines.length * 26 + 20 : 0; // lines + gap
  const qrH = 200 + 24;                              // qr + gap above
  const urlH = 28;

  const totalHeight = PADDING + profileH + titleH + descH + qrH + urlH + PADDING;

  // 4. Draw final canvas
  const canvas = document.createElement('canvas');
  canvas.width = CANVAS_WIDTH;
  canvas.height = totalHeight;
  const ctx = canvas.getContext('2d');

  // Background
  ctx.fillStyle = bgColor;
  ctx.fillRect(0, 0, CANVAS_WIDTH, totalHeight);

  let y = PADDING;

  // Profile image (circle)
  if (profileImg) {
    const imgSize = 90;
    const imgX = (CANVAS_WIDTH - imgSize) / 2;
    ctx.save();
    ctx.beginPath();
    ctx.arc(imgX + imgSize / 2, y + imgSize / 2, imgSize / 2, 0, Math.PI * 2);
    ctx.clip();
    ctx.drawImage(profileImg, imgX, y, imgSize, imgSize);
    ctx.restore();
    y += imgSize + 24;
  }

  // Title
  ctx.fillStyle = textColor;
  ctx.font = 'bold 30px system-ui, sans-serif';
  ctx.textAlign = 'center';
  for (const line of titleLines) {
    ctx.fillText(line, CANVAS_WIDTH / 2, y + 28);
    y += 38;
  }

  // Description
  if (descLines.length > 0) {
    y += 12;
    ctx.font = '18px system-ui, sans-serif';
    ctx.globalAlpha = 0.7;
    for (const line of descLines) {
      ctx.fillText(line, CANVAS_WIDTH / 2, y + 20);
      y += 26;
    }
    ctx.globalAlpha = 1;
  }

  // QR code
  y += 28;
  const qrSize = 180;
  const qrX = (CANVAS_WIDTH - qrSize) / 2;
  if (qrImg) {
    ctx.drawImage(qrImg, qrX, y, qrSize, qrSize);
  }
  y += qrSize + 20;

  // URL
  ctx.font = '14px system-ui, monospace';
  ctx.globalAlpha = 0.6;
  ctx.fillText(pageUrl, CANVAS_WIDTH / 2, y + 14);
  ctx.globalAlpha = 1;

  return canvas;
}

function PageQRDownload({ page }) {
  const [loading, setLoading] = useState(false);

  const handleDownload = async (e) => {
    e.preventDefault();
    e.stopPropagation();
    setLoading(true);
    try {
      const siteUrl = window.location.origin;
      const canvas = await generateQRImage(page, siteUrl);
      canvas.toBlob((blob) => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `qr-${page.url_slug}.png`;
        a.click();
        URL.revokeObjectURL(url);
      }, 'image/png');
    } catch (err) {
      console.error('Error generating QR:', err);
    } finally {
      setLoading(false);
    }
  };

  return (
    <button
      onClick={handleDownload}
      disabled={loading}
      title="Descargar QR"
      className="text-gray-500 hover:text-white transition disabled:opacity-40"
    >
      {loading
        ? <span className="text-xs">...</span>
        : <QrCode className="w-5 h-5" />
      }
    </button>
  );
}

export default PageQRDownload;
