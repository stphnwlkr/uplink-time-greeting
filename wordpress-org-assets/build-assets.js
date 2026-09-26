const sharp = require('sharp');
const path = require('path');
const dir = __dirname;
const source = process.argv[2];
if (!source) throw new Error('Pass the real admin UI screenshot path.');
async function build() {
  await sharp(source).trim({ background: '#ffffff', threshold: 4 }).png().toFile(path.join(dir, 'real-ui-preview.png'));
  await sharp(path.join(dir, 'icon.svg'), { density: 384 }).resize(128, 128).png().toFile(path.join(dir, 'icon-128x128.png'));
  await sharp(path.join(dir, 'icon.svg'), { density: 384 }).resize(256, 256).png().toFile(path.join(dir, 'icon-256x256.png'));
  await sharp(path.join(dir, 'icon.svg'), { density: 384 }).resize(1024, 1024).png().toFile(path.join(dir, 'icon-1024x1024.png'));
  await sharp(path.join(dir, 'banner.svg'), { density: 144 }).resize(1544, 500).png().toFile(path.join(dir, 'banner-1544x500.png'));
  await sharp(path.join(dir, 'banner.svg'), { density: 144 }).resize(772, 250).png().toFile(path.join(dir, 'banner-772x250.png'));
  await sharp(path.join(dir, 'social-1200x630.svg'), { density: 144 }).resize(1200, 630).png().toFile(path.join(dir, 'social-1200x630.png'));
}
build().catch((error) => { console.error(error); process.exit(1); });
