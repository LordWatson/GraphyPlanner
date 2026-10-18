// First strong RTL character: Hebrew, Arabic, Syriac, Thaana, NKo, Samaritan, Mandaic, Arabic
// Extended and the Hebrew/Arabic presentation forms.
const RTL_CHAR = /[\u0590-\u08FF\uFB1D-\uFDFF\uFE70-\uFEFC]/;
// First strong LTR character (Latin, Greek, Cyrillic, Indic, CJK, etc.) — letter blocks that aren't RTL.
const LTR_CHAR = /[A-Za-z\u00C0-\u058F\u0900-\u1FFF\u2C00-\uFB1C]/;

/**
 * Direction of a caption, decided by its first strong character — the same rule the social
 * platforms (and `dir="auto"`) use, so an Arabic caption renders right-to-left and an English
 * caption that merely contains an Arabic word stays left-to-right. Leading emoji, digits,
 * punctuation and hashtag marks are skipped. Empty/neutral text falls back to `ltr`.
 */
export function textDirection(text: string | null | undefined): 'ltr' | 'rtl' {
    for (const char of text ?? '') {
        if (RTL_CHAR.test(char)) {
            return 'rtl';
        }

        if (LTR_CHAR.test(char)) {
            return 'ltr';
        }
    }

    return 'ltr';
}
