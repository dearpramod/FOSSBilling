import { fileURLToPath } from 'url';
import { dirname, join, resolve } from 'path';
import { execFileSync } from 'child_process';
import { readFileSync, writeFileSync } from 'fs';
import esbuild from 'esbuild';
import {
  buildCssFile,
  buildJsFile,
  ensureDir,
  getThemeBuildPaths,
  prepareThemeBuildDirs,
  sharedLoaders,
  writeAssetManifest,
} from '../../../frontend/tools/esbuild-helpers.mts';
import { buildIconSprite } from '../../../frontend/tools/icon-sprite.mts';

const __dirname = dirname(fileURLToPath(import.meta.url));
const isProduction = process.env.NODE_ENV === 'production';
const purgeSafelist = [/^hide-/];
// MeroServer is also built as a standalone theme package in this repository.
const nodeModulesDir = resolve(__dirname, 'node_modules');

async function build() {
  console.log(`Building meroserver (${isProduction ? 'production' : 'development'}) with esbuild + Tailwind ...`);

  const startTime = Date.now();

  try {
    const paths = getThemeBuildPaths(__dirname);
    await prepareThemeBuildDirs(paths);
    await ensureDir(join(paths.cssDir));

    console.log('Generating icon sprite...');
    await buildIconSprite({
      manifestPath: resolve(__dirname, 'icon-manifest.json'),
      outputDir: paths.symbolDir,
      sources: [
        { name: 'custom', dir: resolve(__dirname, 'custom-icons'), variant: 'custom' },
        { name: '@tabler/icons', dir: resolve(nodeModulesDir, '@tabler/icons/icons') },
      ],
    });

    console.log('Bundling JS (Alpine.js + TomSelect)...');
    await buildJsFile({
      entryPoint: resolve(__dirname, 'assets/meroserver.js'),
      outdir: paths.jsDir,
      entryNames: '[name]',
      chunkNames: 'chunks/[name]-[hash]',
      isProduction,
      loader: sharedLoaders,
      splitting: true,
      drop: isProduction ? ['console', 'debugger'] : [],
    });

    console.log('Building Tailwind CSS...');
    const twBin = join(nodeModulesDir, '.bin/tailwindcss');
    const meroserverCssOut = join(paths.cssDir, 'meroserver.css');
    const twArgs = [
      '--input',  './assets/css/meroserver.input.css',
      '--output', meroserverCssOut,
      ...(isProduction ? ['--minify'] : []),
    ];
    execFileSync(twBin, twArgs, { cwd: __dirname, stdio: 'inherit' });

    console.log('Appending theme.css + custom.css into meroserver.css...');
    const twOutput = readFileSync(meroserverCssOut, 'utf8');
    const themeSrc = readFileSync(resolve(__dirname, 'assets/css/theme.css'), 'utf8');
    const customSrc = readFileSync(resolve(__dirname, 'assets/css/custom.css'), 'utf8');
    const homeSrc = readFileSync(resolve(__dirname, 'assets/css/home-saas.css'), 'utf8');
    const merged = twOutput + '\n' + themeSrc + '\n' + customSrc + '\n' + homeSrc;
    if (isProduction) {
      const result = await esbuild.transform(merged, { loader: 'css', minify: true });
      writeFileSync(meroserverCssOut, result.code);
    } else {
      writeFileSync(meroserverCssOut, merged);
    }

    console.log('Building vendor CSS (flag-icons + tom-select)...');
    await buildCssFile({
      entryPoint: resolve(__dirname, 'assets/css/vendor.css'),
      outfile: join(paths.cssDir, 'vendor.css'),
      nodeModulesDir,
      isProduction,
      loader: sharedLoaders,
      themePath: __dirname,
      purge: {
        area: 'client',
        additionalStandardSafelist: purgeSafelist,
      },
    });

    await writeAssetManifest(paths.buildDir, {
      'build/meroserver.js':            '/themes/meroserver/assets/build/js/meroserver.js',
      'build/meroserver.css':           '/themes/meroserver/assets/build/css/meroserver.css',
      'build/vendor.css':               '/themes/meroserver/assets/build/css/vendor.css',
      'build/symbol/icons-sprite.svg':  '/themes/meroserver/assets/build/symbol/icons-sprite.svg',
    });

    const duration = ((Date.now() - startTime) / 1000).toFixed(2);
    console.log(`✓ Build complete in ${duration}s\n`);

  } catch (error) {
    console.error('✗ Build failed:', error);
    process.exit(1);
  }
}

build();
