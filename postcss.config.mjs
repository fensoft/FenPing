import purgecss from '@fullhuman/postcss-purgecss';

const production = process.env.NODE_ENV === 'production';

export default {
  plugins: production
    ? [
        purgecss({
          content: ['./index.html', './frontend/**/*.{js,vue}'],
          defaultExtractor: (content) => content.match(/[\w-/:]+(?<!:)/g) || [],
          safelist: {
            standard: [
              /^(scan-run|scan-row)-(queued|running|complete|failed|timeout|cancelled)$/,
              /^stability-(good|warn|bad)$/
            ],
            greedy: [/data-bs-theme/]
          }
        })
      ]
    : []
};
