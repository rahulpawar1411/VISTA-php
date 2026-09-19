/**
 * Builds ReeferON 10-slide PPTX with screenshots from CRM/new.
 * Run: node build-reeferon-ppt.js
 */
const PptxGenJS = require('pptxgenjs');
const path = require('path');
const fs = require('fs');

const BLUE = '0033A0';
const DARK = '1A1A2E';
const MUTED = '5A6472';
const LIGHT_BG = 'F7F9FC';
const WHITE = 'FFFFFF';
const LINE = 'D8DEE8';

const IMG = path.join(__dirname, '..', 'new');

const files = {
  webDash: 'Screenshot 2026-08-25 143609.png',
  login: 'WhatsApp Image 2026-08-25 at 2.35.35 PM.jpeg',
  splash: 'WhatsApp Image 2026-08-25 at 2.35.35 PM (1).jpeg',
  doTasks: 'WhatsApp Image 2026-08-25 at 2.35.39 PM.jpeg',
  doReports: 'WhatsApp Image 2026-08-25 at 2.35.40 PM.jpeg',
  logDetail: 'WhatsApp Image 2026-08-25 at 2.35.40 PM (1).jpeg',
  subDash: 'WhatsApp Image 2026-08-25 at 2.35.40 PM (2).jpeg',
  inventory: 'WhatsApp Image 2026-08-25 at 2.35.41 PM.jpeg',
  permissions: 'WhatsApp Image 2026-08-25 at 2.35.41 PM (1).jpeg',
  customerEmpty: 'WhatsApp Image 2026-08-25 at 2.35.41 PM (2).jpeg',
  customerLogs: 'WhatsApp Image 2026-08-25 at 2.35.42 PM.jpeg',
  doDash: 'WhatsApp Image 2026-08-25 at 2.35.42 PM (1).jpeg',
};

function img(name) {
  const p = path.join(IMG, name);
  if (!fs.existsSync(p)) throw new Error('Missing image: ' + p);
  return p;
}

async function main() {
  const pptx = new PptxGenJS();
  pptx.defineLayout({ name: 'WIDE', width: 13.333, height: 7.5 });
  pptx.layout = 'WIDE';
  pptx.author = 'ReeferON';
  pptx.title = 'ReeferON — Warehouse Operations Visibility';
  pptx.subject = '10-slide product overview with app screenshots';

  const addFooter = (slide, n) => {
    slide.addText('ReeferON  ·  Warehouse Operations Visibility', {
      x: 0.5, y: 7.05, w: 10.5, h: 0.3,
      fontSize: 10, color: MUTED, fontFace: 'Calibri',
    });
    slide.addText(String(n), {
      x: 12.2, y: 7.05, w: 0.6, h: 0.3,
      fontSize: 10, color: MUTED, fontFace: 'Calibri', align: 'right',
    });
  };

  const titleBar = (slide, title) => {
    slide.addShape(pptx.shapes.RECTANGLE, {
      x: 0, y: 0, w: 13.333, h: 0.15,
      fill: { color: BLUE }, line: { color: BLUE },
    });
    slide.addText(title, {
      x: 0.55, y: 0.35, w: 12.2, h: 0.5,
      fontSize: 26, bold: true, color: DARK, fontFace: 'Calibri',
    });
  };

  const lightBg = (slide) => {
    slide.addShape(pptx.shapes.RECTANGLE, {
      x: 0, y: 0, w: 13.333, h: 7.5,
      fill: { color: LIGHT_BG }, line: { color: LIGHT_BG },
    });
  };

  // ——— SLIDE 1: Title + splash ———
  {
    const s = pptx.addSlide();
    s.addShape(pptx.shapes.RECTANGLE, {
      x: 0, y: 0, w: 13.333, h: 7.5,
      fill: { color: BLUE }, line: { color: BLUE },
    });
    s.addText('ReeferON', {
      x: 0.7, y: 1.8, w: 7, h: 0.9,
      fontSize: 48, bold: true, color: WHITE, fontFace: 'Calibri',
    });
    s.addText('Warehouse Operations Visibility', {
      x: 0.7, y: 2.7, w: 7, h: 0.45,
      fontSize: 22, color: 'B8C7E8', fontFace: 'Calibri',
    });
    s.addText('Cold storage warehouse app\nDaily chamber checks  ·  Box stock  ·  Client portal', {
      x: 0.7, y: 3.5, w: 7, h: 1,
      fontSize: 16, color: WHITE, fontFace: 'Calibri',
    });
    s.addImage({
      path: img(files.splash),
      x: 8.2, y: 0.9, w: 4.3, h: 5.7,
      shadow: { type: 'outer', color: '000000', blur: 18, opacity: 0.35, offset: 6 },
    });
  }

  // ——— SLIDE 2: Problem ———
  {
    const s = pptx.addSlide();
    lightBg(s);
    titleBar(s, 'The Problem');
    const bullets = [
      'Temperature must be checked twice a day (morning and evening)',
      'Box in/out often tracked on paper, WhatsApp, or Excel',
      'Managers cannot see what is Done, Pending, or Late',
      'Field staff often have weak internet inside cold rooms',
      'Clients keep calling for stock and temperature proof',
    ];
    s.addText(
      bullets.map((t) => ({ text: t, options: { bullet: true, breakLine: true } })),
      {
        x: 0.7, y: 1.2, w: 12, h: 5,
        fontSize: 20, color: DARK, fontFace: 'Calibri', paraSpacing: 14,
      }
    );
    addFooter(s, 2);
  }

  // ——— SLIDE 3: Solution + login ———
  {
    const s = pptx.addSlide();
    lightBg(s);
    titleBar(s, 'What ReeferON Does');
    const bullets = [
      'One system for cold warehouse daily operations',
      'Field staff log on mobile (with photo)',
      'Managers watch on mobile and web',
      'Clients see only their own data',
      'Stock is tracked by box count (simple)',
      'Important changes need approve / deny',
    ];
    s.addText(
      bullets.map((t) => ({ text: t, options: { bullet: true, breakLine: true } })),
      {
        x: 0.55, y: 1.15, w: 7.2, h: 5,
        fontSize: 18, color: DARK, fontFace: 'Calibri', paraSpacing: 12,
      }
    );
    s.addImage({
      path: img(files.login),
      x: 8.3, y: 1.0, w: 4.2, h: 5.6,
      shadow: { type: 'outer', color: '000000', blur: 12, opacity: 0.25, offset: 4 },
    });
    addFooter(s, 3);
  }

  // ——— SLIDE 4: Four roles with screens ———
  {
    const s = pptx.addSlide();
    lightBg(s);
    titleBar(s, 'Four Roles');

    const roles = [
      { label: 'Super Admin · Web', file: files.webDash, x: 0.35 },
      { label: 'Sub Admin · Mobile', file: files.subDash, x: 3.55 },
      { label: 'DO · Mobile', file: files.doDash, x: 6.75 },
      { label: 'Customer · Mobile', file: files.customerLogs, x: 9.95 },
    ];
    roles.forEach((r) => {
      s.addText(r.label, {
        x: r.x, y: 0.95, w: 3.0, h: 0.35,
        fontSize: 12, bold: true, color: BLUE, fontFace: 'Calibri', align: 'center',
      });
      s.addImage({
        path: img(r.file),
        x: r.x, y: 1.35, w: 3.0, h: r.file === files.webDash ? 4.0 : 5.0,
        shadow: { type: 'outer', color: '000000', blur: 8, opacity: 0.2, offset: 3 },
      });
    });
    s.addText('Each person opens only their own screen. Jobs are not mixed.', {
      x: 0.5, y: 6.55, w: 12.3, h: 0.35,
      fontSize: 14, italic: true, color: MUTED, fontFace: 'Calibri',
    });
    addFooter(s, 4);
  }

  // ——— SLIDE 5: Role responsibilities ———
  {
    const s = pptx.addSlide();
    lightBg(s);
    titleBar(s, 'Role Responsibilities');
    const rows = [
      { role: 'Super Admin', text: 'Create users, warehouses, clients · see all reports · approve requests' },
      { role: 'Sub Admin', text: 'Watch DO progress · manage chambers/clients · approve requests on phone' },
      { role: 'DO', text: 'Do morning/evening checks · inward/outward · works offline' },
      { role: 'Customer', text: 'View own logs and box stock (read only)' },
    ];
    rows.forEach((r, i) => {
      const y = 1.15 + i * 1.25;
      s.addShape(pptx.shapes.ROUNDED_RECTANGLE, {
        x: 0.55, y, w: 7.4, h: 1.1,
        fill: { color: WHITE }, line: { color: LINE, pt: 1 }, rectRadius: 0.08,
      });
      s.addText(r.role, {
        x: 0.8, y: y + 0.18, w: 6.9, h: 0.32,
        fontSize: 16, bold: true, color: BLUE, fontFace: 'Calibri',
      });
      s.addText(r.text, {
        x: 0.8, y: y + 0.52, w: 6.9, h: 0.45,
        fontSize: 14, color: DARK, fontFace: 'Calibri',
      });
    });
    s.addImage({
      path: img(files.permissions),
      x: 8.3, y: 1.15, w: 4.3, h: 5.5,
      shadow: { type: 'outer', color: '000000', blur: 10, opacity: 0.22, offset: 3 },
    });
    addFooter(s, 5);
  }

  // ——— SLIDE 6: How it works ———
  {
    const s = pptx.addSlide();
    lightBg(s);
    titleBar(s, 'How It Works');
    const steps = [
      'Set up warehouses and clients',
      'Assign a DO to a warehouse',
      'Create chambers and assign clients',
      'DO completes morning and evening tasks',
      'Manager sees Done / Pending / Overdue',
      'Customer checks their own stock and logs',
    ];
    steps.forEach((t, i) => {
      const y = 1.15 + i * 0.85;
      s.addShape(pptx.shapes.OVAL, {
        x: 0.7, y: y + 0.05, w: 0.5, h: 0.5,
        fill: { color: BLUE }, line: { color: BLUE },
      });
      s.addText(String(i + 1), {
        x: 0.7, y: y + 0.1, w: 0.5, h: 0.4,
        fontSize: 16, bold: true, color: WHITE, align: 'center', fontFace: 'Calibri',
      });
      s.addText(t, {
        x: 1.45, y: y + 0.08, w: 6.5, h: 0.45,
        fontSize: 18, color: DARK, fontFace: 'Calibri',
      });
    });
    s.addImage({
      path: img(files.webDash),
      x: 8.2, y: 1.3, w: 4.5, h: 5.0,
      shadow: { type: 'outer', color: '000000', blur: 10, opacity: 0.2, offset: 3 },
    });
    addFooter(s, 6);
  }

  // ——— SLIDE 7: DO day ———
  {
    const s = pptx.addSlide();
    lightBg(s);
    titleBar(s, 'A Day for Field Staff (DO)');
    const items = [
      ['Morning', 'Open Tasks → Pending → Record Log (temperature + photo)'],
      ['Day', 'Fill Inward / Outward when trucks come or leave'],
      ['Evening', 'Do evening checks again'],
      ['No internet', 'Save on phone → Sync later'],
      ['Mistake', 'Ask permission → Manager approves → Then edit'],
    ];
    items.forEach((row, i) => {
      const y = 1.1 + i * 0.72;
      s.addText(row[0], {
        x: 0.5, y, w: 2.1, h: 0.5,
        fontSize: 14, bold: true, color: BLUE, fontFace: 'Calibri',
      });
      s.addText(row[1], {
        x: 2.6, y, w: 5.3, h: 0.5,
        fontSize: 14, color: DARK, fontFace: 'Calibri',
      });
    });
    s.addText('Status: Pending · Completed · Overdue', {
      x: 0.5, y: 4.85, w: 7.4, h: 0.35,
      fontSize: 14, bold: true, color: MUTED, fontFace: 'Calibri',
    });
    s.addImage({
      path: img(files.doTasks),
      x: 8.2, y: 1.0, w: 2.35, h: 5.5,
      shadow: { type: 'outer', color: '000000', blur: 8, opacity: 0.2, offset: 3 },
    });
    s.addImage({
      path: img(files.doDash),
      x: 10.7, y: 1.0, w: 2.35, h: 5.5,
      shadow: { type: 'outer', color: '000000', blur: 8, opacity: 0.2, offset: 3 },
    });
    addFooter(s, 7);
  }

  // ——— SLIDE 8: Key features with proof screens ———
  {
    const s = pptx.addSlide();
    lightBg(s);
    titleBar(s, 'Key Features');
    const left = [
      'Morning and evening chamber tasks',
      'Photo proof (and location when available)',
      'Inward and outward box forms',
      'Offline save + sync',
      'Permission approve / deny',
      'Daily box tracker for managers',
      'Client portal (own data only)',
      'Multi-warehouse support',
    ];
    s.addText(
      left.map((t) => ({ text: t, options: { bullet: true, breakLine: true } })),
      {
        x: 0.5, y: 1.1, w: 5.5, h: 5.2,
        fontSize: 16, color: DARK, fontFace: 'Calibri', paraSpacing: 10,
      }
    );
    s.addImage({
      path: img(files.logDetail),
      x: 6.2, y: 1.0, w: 2.25, h: 5.5,
      shadow: { type: 'outer', color: '000000', blur: 8, opacity: 0.2, offset: 3 },
    });
    s.addImage({
      path: img(files.doReports),
      x: 8.55, y: 1.0, w: 2.25, h: 5.5,
      shadow: { type: 'outer', color: '000000', blur: 8, opacity: 0.2, offset: 3 },
    });
    s.addImage({
      path: img(files.inventory),
      x: 10.9, y: 1.0, w: 2.25, h: 5.5,
      shadow: { type: 'outer', color: '000000', blur: 8, opacity: 0.2, offset: 3 },
    });
    addFooter(s, 8);
  }

  // ——— SLIDE 9: Benefits ———
  {
    const s = pptx.addSlide();
    lightBg(s);
    titleBar(s, 'Benefits');
    s.addTable(
      [
        [
          { text: 'Who', options: { bold: true, color: WHITE, fill: { color: BLUE } } },
          { text: 'Benefit', options: { bold: true, color: WHITE, fill: { color: BLUE } } },
        ],
        ['Owner', 'See all sites in one place'],
        ['Manager', 'Know Done / Pending / Late quickly'],
        ['DO', 'Clear task list, works offline'],
        ['Client', 'Check stock without calling'],
        ['Team', 'Clear record of who changed what'],
      ],
      {
        x: 0.5, y: 1.15, w: 7.3, colW: [2.0, 5.3],
        border: [
          { pt: 0.5, color: LINE }, { pt: 0.5, color: LINE },
          { pt: 0.5, color: LINE }, { pt: 0.5, color: LINE },
        ],
        fontFace: 'Calibri', fontSize: 15, color: DARK,
        align: 'left', valign: 'middle',
      }
    );
    s.addText('Not billing software · Not truck routing · Not SKU inventory (boxes only)', {
      x: 0.5, y: 5.9, w: 7.3, h: 0.4,
      fontSize: 12, color: MUTED, fontFace: 'Calibri',
    });
    s.addImage({
      path: img(files.subDash),
      x: 8.2, y: 1.05, w: 4.4, h: 5.5,
      shadow: { type: 'outer', color: '000000', blur: 10, opacity: 0.22, offset: 3 },
    });
    addFooter(s, 9);
  }

  // ——— SLIDE 10: Summary ———
  {
    const s = pptx.addSlide();
    lightBg(s);
    titleBar(s, 'Summary');
    const points = [
      'Four roles — Admin, Manager, DO, Customer',
      'Daily morning and evening chamber checks',
      'Box counts for stock',
      'Offline work for field staff',
      'Permissions keep data safe',
    ];
    points.forEach((t, i) => {
      s.addText(`${i + 1}.  ${t}`, {
        x: 0.6, y: 1.2 + i * 0.65, w: 7.3, h: 0.5,
        fontSize: 18, color: DARK, fontFace: 'Calibri',
      });
    });
    s.addText('ReeferON = Warehouse Operations Visibility', {
      x: 0.6, y: 4.7, w: 7.3, h: 0.4,
      fontSize: 18, bold: true, color: BLUE, fontFace: 'Calibri',
    });
    s.addText('Thank you  ·  Demo / Q&A', {
      x: 0.6, y: 5.25, w: 7.3, h: 0.35,
      fontSize: 15, color: MUTED, fontFace: 'Calibri',
    });
    s.addImage({
      path: img(files.splash),
      x: 8.4, y: 1.15, w: 4.2, h: 5.4,
      shadow: { type: 'outer', color: '000000', blur: 10, opacity: 0.22, offset: 3 },
    });
    addFooter(s, 10);
  }

  const outDocs = path.join(__dirname, 'ReeferON-10-Slides.pptx');
  const outDesktop = path.join(require('os').homedir(), 'Desktop', 'ReeferON-10-Slides.pptx');
  await pptx.writeFile({ fileName: outDocs });
  await pptx.writeFile({ fileName: outDesktop });
  console.log('Created:', outDocs);
  console.log('Created:', outDesktop);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
