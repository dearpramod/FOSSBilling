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
const nodeModulesDir = resolve(__dirname, 'node_modules');

async function build() {
  console.log(`Building merotheme (${isProduction ? 'production' : 'development'}) with esbuild + Tailwind ...`);

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
      entryPoint: resolve(__dirname, 'assets/merotheme.js'),
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
    const merothemeCssOut = join(paths.cssDir, 'merotheme.css');
    const twArgs = [
      '--input',  './assets/css/merotheme.input.css',
      '--output', merothemeCssOut,
      ...(isProduction ? ['--minify'] : []),
    ];
    execFileSync(twBin, twArgs, { cwd: __dirname, stdio: 'inherit' });

    console.log('Appending theme.css + custom.css into merotheme.css...');
    const twOutput = readFileSync(merothemeCssOut, 'utf8');
    const themeSrc = readFileSync(resolve(__dirname, 'assets/css/theme.css'), 'utf8');
    const customSrc = readFileSync(resolve(__dirname, 'assets/css/custom.css'), 'utf8');
    const merged = twOutput + '\n' + themeSrc + '\n' + customSrc;
    if (isProduction) {
      const result = await esbuild.transform(merged, { loader: 'css', minify: true });
      writeFileSync(merothemeCssOut, result.code);
    } else {
      writeFileSync(merothemeCssOut, merged);
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
      'build/merotheme.js':             '/themes/merotheme/assets/build/js/merotheme.js',
      'build/merotheme.css':            '/themes/merotheme/assets/build/css/merotheme.css',
      'build/vendor.css':               '/themes/merotheme/assets/build/css/vendor.css',
      'build/symbol/icons-sprite.svg':  '/themes/merotheme/assets/build/symbol/icons-sprite.svg',
    });

    const duration = ((Date.now() - startTime) / 1000).toFixed(2);
    console.log(`✓ Build complete in ${duration}s\n`);

  } catch (error) {
    console.error('✗ Build failed:', error);
    process.exit(1);
  }
}

build();
