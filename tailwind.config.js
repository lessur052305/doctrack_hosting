/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './resources/**/*.blade.php',
    './resources/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        // Trustworthy navy for primary actions & branding — same hue family
        // as before, deepened and given more saturation at the working end
        // of the scale (500-900) so buttons, links and the sidebar read as
        // confident rather than washed out.
        primary: {
          50: '#f0f5fb', 100: '#dae6f5', 200: '#b3cce9', 300: '#82abd6',
          400: '#5a8cc2', 500: '#3d6da8', 600: '#2f5486', 700: '#26436b',
          800: '#1e3455', 900: '#16273f', 950: '#0e1929',
        },
        // Neutral grays for structural chrome
        surface: {
          50: '#f8fafc', 100: '#f1f5f9', 200: '#e2e8f0', 300: '#cbd5e1',
          400: '#94a3b8', 500: '#64748b', 600: '#475569', 700: '#334155',
          800: '#1e293b', 900: '#0f172a',
        },
        // Semantic state colors used across status badges, steppers &
        // alerts — each carries a 100 (soft ring/border) and 600 (hover)
        // stop alongside the existing 50/500/700 so components have finer
        // control than a single flat tone.
        approved: { 50: '#ecfdf5', 100: '#d1fae5', 500: '#10b981', 600: '#059669', 700: '#047857' },
        processing: { 50: '#fffbeb', 100: '#fef3c7', 500: '#f59e0b', 600: '#d97706', 700: '#b45309' },
        rejected: { 50: '#fef2f2', 100: '#fee2e2', 500: '#ef4444', 600: '#dc2626', 700: '#b91c1c' },
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      boxShadow: {
        // Soft, close contact shadow for resting cards — barely-there depth.
        card: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px -1px rgb(15 23 42 / 0.06)',
        // Slightly lifted state for hoverable cards/rows.
        'card-hover': '0 2px 4px -1px rgb(15 23 42 / 0.06), 0 8px 16px -4px rgb(15 23 42 / 0.08)',
        // Modals, dropdowns, anything floating above the page.
        elevated: '0 4px 6px -2px rgb(15 23 42 / 0.06), 0 16px 32px -8px rgb(15 23 42 / 0.16)',
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
  ],
}
