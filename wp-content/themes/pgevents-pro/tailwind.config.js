import plugin from "tailwindcss/plugin";

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        "./**/*.php",
        "./assets/**/*.js",
    ],
    theme: {
        extend: {},
    },
    plugins: [
        plugin(function ({ addVariant }) {
            addVariant("rtl", '[dir="rtl"] &');
            addVariant("ltr", '[dir="ltr"] &');
        }),
    ],
};
