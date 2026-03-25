/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './index.html',
    './src/**/*.{vue,js,ts,jsx,tsx}',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      colors: {
        primary: {
          DEFAULT: '#ff0080',
          50:  '#fff0f6',
          100: '#ffe0ed',
          200: '#ffc2db',
          300: '#ff94c0',
          400: '#ff55a0',
          500: '#ff0080',
          600: '#e6006e',
          700: '#c2005c',
          800: '#9e004b',
          900: '#7a003b',
        },
      },
      boxShadow: {
        'primary': '0 4px 20px -2px rgba(255, 0, 128, 0.25)',
      },
    },
  },
  plugins: [],
}
