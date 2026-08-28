/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Vazirmatn', 'Tahoma', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      colors: {
        // Custom color palette for better dark mode support
        primary: {
          50: '#eff6ff',
          100: '#dbeafe',
          200: '#bfdbfe',
          300: '#93c5fd',
          400: '#60a5fa',
          500: '#3b82f6',
          600: '#2563eb',
          700: '#1d4ed8',
          800: '#1e40af',
          900: '#1e3a8a',
        },
        gray: {
          50: '#f9fafb',
          100: '#f3f4f6',
          200: '#e5e7eb',
          300: '#d1d5db',
          400: '#9ca3af',
          500: '#6b7280',
          600: '#4b5563',
          700: '#374151',
          800: '#1f2937',
          900: '#111827',
        }
      },
      backgroundColor: {
        // Dark mode backgrounds
        'dark-primary': '#1f2937',
        'dark-secondary': '#111827',
        'dark-tertiary': '#374151',
      },
      textColor: {
        // Dark mode text colors
        'dark-primary': '#f9fafb',
        'dark-secondary': '#e5e7eb',
        'dark-tertiary': '#d1d5db',
      }
    },
  },
  plugins: [],
}
