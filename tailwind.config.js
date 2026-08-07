import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            colors: {
                hermes: {
                    bg: 'rgb(var(--hermes-bg) / <alpha-value>)',
                    surface: 'rgb(var(--hermes-surface) / <alpha-value>)',
                    card: 'rgb(var(--hermes-card) / <alpha-value>)',
                    border: 'rgb(var(--hermes-border) / <alpha-value>)',
                    hover: 'rgb(var(--hermes-hover) / <alpha-value>)',
                    text: 'rgb(var(--hermes-text) / <alpha-value>)',
                    muted: 'rgb(var(--hermes-muted) / <alpha-value>)',
                    accent: 'rgb(var(--hermes-accent) / <alpha-value>)',
                    success: 'rgb(var(--hermes-success) / <alpha-value>)',
                    warning: 'rgb(var(--hermes-warning) / <alpha-value>)',
                    danger: 'rgb(var(--hermes-danger) / <alpha-value>)',
                },
                primary: '#FFFFFF',
                secondary: '#F9F9F9',
                main: '#181818',
                button: '#EEEDF0',
                accent: {
                    soft: '#F3EFFF',
                    pale: '#D7EFAE',
                    warm: '#FFF1D1',
                    rose: '#FFDCD8',
                },
            },
            fontFamily: {
                sans: ['Inter', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            animation: {
                'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
            },
        },
    },

    plugins: [forms],
};
