#!/usr/bin/env python3
import json
import os
from pathlib import Path

LOCALIZATION_DIR = Path("/home/kim/repo/github/Tachyon/tachyon/v/0.0.0/app/localization")

# Languages that DO NOT have admin.json (skip TAB_BRANDING for these)
NO_ADMIN_LANGS = {"ar", "bg", "el", "et", "is", "ko", "lv", "ro", "tr"}

# ADMIN translations for TAB_BRANDING section
ADMIN_TRANSLATIONS = {
    "BUTTON_DELETE_LOGO": {
        "be": "Выдаліць лагатып",
        "cs": "Odstranit logo",
        "da": "Fjern logo",
        "de": "Logo entfernen",
        "es": "Eliminar logo",
        "et": "Eemalda logo",
        "eu": "Ezabatu logotipoa",
        "fa": "حذف لوگو",
        "fi": "Poista logo",
        "fr": "Supprimer le logo",
        "hu": "Logó eltávolítása",
        "id": "Hapus logo",
        "it": "Rimuovi logo",
        "ja": "ロゴを削除",
        "lt": "Pašalinti logotipą",
        "lv": "Noņemt logotipu",
        "nb": "Fjern logo",
        "nl": "Logo verwijderen",
        "pl": "Usuń logo",
        "pt": "Remover logo",
        "pt-BR": "Remover logo",
        "ru": "Удалить логотип",
        "sk": "Odstrániť logo",
        "sl": "Odstrani logotip",
        "sv": "Ta bort logotyp",
        "tr": "Logoyu kaldır",
        "uk": "Видалити логотип",
        "vi": "Xóa logo",
        "zh": "删除徽标",
        "zh-TW": "移除標誌",
    },
    "BUTTON_UPLOAD_LOGO": {
        "be": "Загрузіць лагатып",
        "cs": "Nahrát logo",
        "da": "Upload logo",
        "de": "Logo hochladen",
        "es": "Subir logo",
        "et": "Laadi logo üles",
        "eu": "Igo logotipoa",
        "fa": "آپلود لوگو",
        "fi": "Lataa logo",
        "fr": "Télécharger le logo",
        "hu": "Logó feltöltése",
        "id": "Unggah logo",
        "it": "Carica logo",
        "ja": "ロゴをアップロード",
        "lt": "Įkelti logotipą",
        "lv": "Augšupielādēt logotipu",
        "nb": "Last opp logo",
        "nl": "Logo uploaden",
        "pl": "Prześlij logo",
        "pt": "Enviar logo",
        "pt-BR": "Enviar logo",
        "ru": "Загрузить логотип",
        "sk": "Nahrať logo",
        "sl": "Naloži logotip",
        "sv": "Ladda upp logotyp",
        "tr": "Logo yükle",
        "uk": "Завантажити логотип",
        "vi": "Tải logo lên",
        "zh": "上传徽标",
        "zh-TW": "上傳標誌",
    },
    "LABEL_LOGIN_LOGO": {
        "be": "Лагатып",
        "cs": "Logo",
        "da": "Logo",
        "de": "Logo",
        "es": "Logo",
        "et": "Logo",
        "eu": "Logotipoa",
        "fa": "لوگو",
        "fi": "Logo",
        "fr": "Logo",
        "hu": "Logó",
        "id": "Logo",
        "it": "Logo",
        "ja": "ロゴ",
        "lt": "Logotipas",
        "lv": "Logotips",
        "nb": "Logo",
        "nl": "Logo",
        "pl": "Logo",
        "pt": "Logo",
        "pt-BR": "Logo",
        "ru": "Логотип",
        "sk": "Logo",
        "sl": "Logotip",
        "sv": "Logotyp",
        "tr": "Logo",
        "uk": "Логотип",
        "vi": "Logo",
        "zh": "标志",
        "zh-TW": "標誌",
    },
    "LABEL_NO_LOGO": {
        "be": "Лагатып не ўсталяваны",
        "cs": "Žádné logo není nastaveno",
        "da": "Intet logo angivet",
        "de": "Kein Logo festgelegt",
        "es": "No hay logo configurado",
        "et": "Logot pole määratud",
        "eu": "Ez da logorik ezarri",
        "fa": "هیچ لوگویی تنظیم نشده",
        "fi": "Logoa ei ole asetettu",
        "fr": "Aucun logo défini",
        "hu": "Nincs logó beállítva",
        "id": "Tidak ada logo yang diatur",
        "it": "Nessun logo impostato",
        "ja": "ロゴが設定されていません",
        "lt": "Logo nenustatytas",
        "lv": "Nav iestatīts logotips",
        "nb": "Ingen logo angitt",
        "nl": "Geen logo ingesteld",
        "pl": "Brak ustawionego logo",
        "pt": "Nenhum logo definido",
        "pt-BR": "Nenhum logo definido",
        "ru": "Логотип не установлен",
        "sk": "Žiadne logo nie je nastavené",
        "sl": "Logotip ni nastavljen",
        "sv": "Ingen logotyp angiven",
        "tr": "Logo ayarlanmamış",
        "uk": "Логотип не встановлено",
        "vi": "Chưa đặt logo",
        "zh": "未设置标志",
        "zh-TW": "未設置標誌",
    },
    "LEGEND_LOGO": {
        "be": "Лагатып уваходу",
        "cs": "Logo přihlašovací stránky",
        "da": "Login-logo",
        "de": "Anmelde-Logo",
        "es": "Logo de inicio de sesión",
        "et": "Sisselogimise logo",
        "eu": "Saioa hasteko logotipoa",
        "fa": "لوگوی ورود",
        "fi": "Kirjautumislogo",
        "fr": "Logo de connexion",
        "hu": "Bejelentkezési logó",
        "id": "Logo masuk",
        "it": "Logo di accesso",
        "ja": "ログインロゴ",
        "lt": "Prisijungimo logotipas",
        "lv": "Pieteikšanās logotips",
        "nb": "Innloggingslogo",
        "nl": "Inloglogo",
        "pl": "Logo logowania",
        "pt": "Logo de login",
        "pt-BR": "Logo de login",
        "ru": "Логотип входа",
        "sk": "Logo prihlásenia",
        "sl": "Logotip prijave",
        "sv": "Inloggningslogotyp",
        "tr": "Giriş logosu",
        "uk": "Логотип входу",
        "vi": "Logo đăng nhập",
        "zh": "登录标志",
        "zh-TW": "登入標誌",
    },
}

# USER translations - all languages
USER_TRANSLATIONS = {
    "BUTTON_SEND_UNDO": {
        "ar": "تراجع",
        "be": "Адмяніць",
        "bg": "Отмяна",
        "cs": "Zpět",
        "da": "Fortryd",
        "de": "Rückgängig",
        "el": "Αναίρεση",
        "es": "Deshacer",
        "et": "Võta tagasi",
        "eu": "Desegin",
        "fa": "واگرد",
        "fi": "Kumoa",
        "fr": "Annuler",
        "hu": "Visszavonás",
        "id": "Batalkan",
        "is": "Afturkalla",
        "it": "Annulla",
        "ja": "元に戻す",
        "ko": "실행 취소",
        "lt": "Atšaukti",
        "lv": "Atsaukt",
        "nb": "Angre",
        "nl": "Ongedaan maken",
        "pl": "Cofnij",
        "pt": "Desfazer",
        "pt-BR": "Desfazer",
        "ro": "Anulare",
        "ru": "Отменить",
        "sk": "Späť",
        "sl": "Razveljavi",
        "sv": "Ångra",
        "tr": "Geri al",
        "uk": "Скасувати",
        "vi": "Hoàn tác",
        "zh": "撤销",
        "zh-TW": "復原",
    },
    "UNDO_SEND_DELAY_LABEL": {
        "ar": "تأخير إلغاء الإرسال",
        "be": "Затрымка адмены адпраўкі",
        "bg": "Закъснение за отмяна на изпращане",
        "cs": "Zpoždění pro odvolání odeslání",
        "da": "Forsinkelse for fortryd send",
        "de": "Verzögerung zum Rückgängigmachen",
        "el": "Καθυστέρηση αναίρεσης αποστολής",
        "es": "Retraso para deshacer envío",
        "et": "Saatmise tagasivõtmise viivitus",
        "eu": "Bidalketak desegin atzerapena",
        "fa": "تأخیر واگرد ارسال",
        "fi": "Lähetyksen kumoamisviive",
        "fr": "Délai d'annulation d'envoi",
        "hu": "Küldés visszavonásának késleltetése",
        "id": "Penundaan batalkan kirim",
        "is": "Seinkun á afturköllun sendingar",
        "it": "Ritardo per annullare l'invio",
        "ja": "送信取り消し遅延",
        "ko": "전송 취소 지연",
        "lt": "Siuntimo atšaukimo vėlavimas",
        "lv": "Sūtīšanas atsaukšanas aizkavēšanās",
        "nb": "Forsinkelse for angre sending",
        "nl": "Vertraging voor ongedaan maken verzenden",
        "pl": "Opóźnienie cofnięcia wysyłki",
        "pt": "Atraso para desfazer envio",
        "pt-BR": "Atraso para desfazer envio",
        "ro": "Întârziere anulare trimitere",
        "ru": "Задержка отмены отправки",
        "sk": "Oneskorenie späť odoslania",
        "sl": "Zamuda razveljavitve pošiljanja",
        "sv": "Fördröjning för ångra sändning",
        "tr": "Göndermeyi geri alma gecikmesi",
        "uk": "Затримка скасування відправлення",
        "vi": "Độ trễ hoàn tác gửi",
        "zh": "撤销发送延迟",
        "zh-TW": "撤銷傳送延遲",
    },
    "UNDO_SEND_DELAY_OFF": {
        "ar": "إيقاف",
        "be": "Выкл.",
        "bg": "Изкл.",
        "cs": "Vypnuto",
        "da": "Fra",
        "de": "Aus",
        "el": "Απενεργοποιημένο",
        "es": "Desactivado",
        "et": "Väljas",
        "eu": "Itzalita",
        "fa": "خاموش",
        "fi": "Pois",
        "fr": "Désactivé",
        "hu": "Ki",
        "id": "Nonaktif",
        "is": "Slökkt",
        "it": "Disattivato",
        "ja": "オフ",
        "ko": "꺼짐",
        "lt": "Išjungta",
        "lv": "Izslēgts",
        "nb": "Av",
        "nl": "Uit",
        "pl": "Wyłączony",
        "pt": "Desligado",
        "pt-BR": "Desligado",
        "ro": "Dezactivat",
        "ru": "Выкл.",
        "sk": "Vypnuté",
        "sl": "Izklopljeno",
        "sv": "Av",
        "tr": "Kapalı",
        "uk": "Вимк.",
        "vi": "Tắt",
        "zh": "关闭",
        "zh-TW": "關閉",
    },
    "REMEMBER_FOR_SESSION": {
        "ar": "تذكر لهذه الجلسة",
        "be": "Запомніць на гэты сеанс",
        "bg": "Запомни за тази сесия",
        "cs": "Zapamatovat pro tuto relaci",
        "da": "Husk for denne session",
        "el": "Να θυμάσαι για αυτή τη συνεδρία",
        "es": "Recordar para esta sesión",
        "et": "Meenuta selle seansi jaoks",
        "eu": "Gogoratu saio honetarako",
        "fa": "برای این جلسه به خاطر بسپار",
        "fi": "Muista tämä istunto",
        "fr": "Se souvenir pour cette session",
        "hu": "Megjegyez erre a munkamenetre",
        "id": "Ingat untuk sesi ini",
        "is": "Muna þessa lotu",
        "it": "Ricorda per questa sessione",
        "ja": "このセッションを記憶する",
        "ko": "이 세션 동안 기억",
        "lt": "Prisiminti šiam seansui",
        "lv": "Atcerēties šai sesijai",
        "nb": "Husk for denne sesjonen",
        "pl": "Zapamiętaj na tę sesję",
        "pt": "Lembrar para esta sessão",
        "pt-BR": "Lembrar para esta sessão",
        "ro": "Ține minte pentru această sesiune",
        "sk": "Zapamätať pre túto reláciu",
        "sl": "Zapomni si za to sejo",
        "sv": "Kom ihåg för denna session",
        "tr": "Bu oturum için hatırla",
        "uk": "Запам'ятати для цього сеансу",
        "vi": "Nhớ cho phiên này",
        "zh": "记住此次会话",
        "zh-TW": "記住此次工作階段",
    },
    "REMEMBER_PERMANENT": {
        "ar": "تذكر بشكل دائم",
        "be": "Запомніць назаўсёды",
        "bg": "Запомни за постоянно",
        "cs": "Zapamatovat trvale",
        "da": "Husk permanent",
        "el": "Να θυμάσαι μόνιμα",
        "es": "Recordar permanentemente",
        "et": "Meenuta püsivalt",
        "eu": "Gogoratu betirako",
        "fa": "به خاطر بسپار به طور دائمی",
        "fi": "Muista pysyvästi",
        "fr": "Se souvenir définitivement",
        "hu": "Állandó megjegyzés",
        "id": "Ingat secara permanen",
        "is": "Muna varanlega",
        "it": "Ricorda in modo permanente",
        "ja": "永続的に記憶する",
        "ko": "영구적으로 기억",
        "lt": "Prisiminti visam laikui",
        "lv": "Atcerēties pastāvīgi",
        "nb": "Husk permanent",
        "pl": "Zapamiętaj na stałe",
        "pt": "Lembrar permanentemente",
        "pt-BR": "Lembrar permanentemente",
        "ro": "Ține minte permanent",
        "sk": "Zapamätať trvalo",
        "sl": "Zapomni si trajno",
        "sv": "Kom ihåg permanent",
        "tr": "Kalıcı olarak hatırla",
        "uk": "Запам'ятати назавжди",
        "vi": "Nhớ vĩnh viễn",
        "zh": "永久记住",
        "zh-TW": "永久記住",
    },
    "LABEL_STORED_PASS": {
        "ar": "عبارة المرور المحفوظة",
        "be": "Захаваная парольная фраза",
        "bg": "Запомнена парола",
        "cs": "Zapamatovaná přístupová fráze",
        "da": "Gemt adgangssætning",
        "el": "Αποθηκευμένη φράση πρόσβασης",
        "es": "Contraseña recordada",
        "et": "Mäletatud parool",
        "eu": "Gordetako pasahitza",
        "fa": "عبارت عبور به خاطر سپرده شده",
        "fi": "Tallennettu salauslause",
        "fr": "Phrase secrète mémorisée",
        "hu": "Megjegyzett jelszó",
        "id": "Kata sandi tersimpan",
        "is": "Geymt aðgangsorð",
        "it": "Passphrase memorizzata",
        "ja": "記憶されたパスフレーズ",
        "ko": "저장된 암호 문구",
        "lt": "Įsiminta slaptažodžio frazė",
        "lv": "Atcerētā paroles frāze",
        "nb": "Lagret passfrase",
        "pl": "Zapamiętane hasło",
        "pt": "Frase de acesso lembrada",
        "pt-BR": "Senha guardada",
        "ro": "Frază de acces memorată",
        "sk": "Zapamätaná prístupová fráza",
        "sl": "Zapomnjena geslo",
        "sv": "Sparad lösenfras",
        "tr": "Hatırlanan parola ifadesi",
        "uk": "Запам'ятана парольна фраза",
        "vi": "Cụm mật khẩu đã ghi nhớ",
        "zh": "已记住的密语",
        "zh-TW": "已記住的密語",
    },
    "SORT_ARRIVAL_ASC": {
        "cs": "Přijaté vzestupně",
    },
    "SORT_ARRIVAL_DESC": {
        "cs": "Přijaté sestupně",
    },
}

# Languages that should NOT have REMEMBER_FOR_SESSION, REMEMBER_PERMANENT, LABEL_STORED_PASS
SKIP_REMEMBER_LANGS = {"de", "nl", "ru"}


def patch_admin_files():
    """Add TAB_BRANDING entries to admin.json files"""
    print("Patching admin.json files...")
    langs_with_admin = [d for d in LOCALIZATION_DIR.iterdir() if d.is_dir() and (d / "admin.json").exists()]

    for lang_dir in sorted(langs_with_admin):
        lang_code = lang_dir.name
        admin_file = lang_dir / "admin.json"

        with open(admin_file, 'r', encoding='utf-8') as f:
            data = json.load(f)

        # Ensure TAB_BRANDING section exists
        if "TAB_BRANDING" not in data:
            data["TAB_BRANDING"] = {}

        # Add missing keys
        added_count = 0
        for key, translations in ADMIN_TRANSLATIONS.items():
            if key not in data["TAB_BRANDING"]:
                if lang_code in translations:
                    data["TAB_BRANDING"][key] = translations[lang_code]
                    added_count += 1

        # Write back
        if added_count > 0:
            with open(admin_file, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
                f.write('\n')  # Ensure newline at end, but no trailing newlines
            print(f"  {lang_code}: added {added_count} keys")
        else:
            print(f"  {lang_code}: all keys present")


def patch_user_files():
    """Add COMPOSE and GLOBAL entries to user.json files"""
    print("\nPatching user.json files...")
    langs_with_user = [d for d in LOCALIZATION_DIR.iterdir() if d.is_dir() and (d / "user.json").exists()]

    for lang_dir in sorted(langs_with_user):
        lang_code = lang_dir.name
        user_file = lang_dir / "user.json"

        with open(user_file, 'r', encoding='utf-8') as f:
            data = json.load(f)

        added_count = 0

        # Add COMPOSE section entries (all languages)
        if "COMPOSE" not in data:
            data["COMPOSE"] = {}

        for key in ["BUTTON_SEND_UNDO", "UNDO_SEND_DELAY_LABEL", "UNDO_SEND_DELAY_OFF"]:
            if key not in data["COMPOSE"]:
                if lang_code in USER_TRANSLATIONS[key]:
                    data["COMPOSE"][key] = USER_TRANSLATIONS[key][lang_code]
                    added_count += 1

        # Add GLOBAL section entries (except de, nl, ru)
        if lang_code not in SKIP_REMEMBER_LANGS:
            if "GLOBAL" not in data:
                data["GLOBAL"] = {}

            for key in ["REMEMBER_FOR_SESSION", "REMEMBER_PERMANENT"]:
                if key not in data["GLOBAL"]:
                    if lang_code in USER_TRANSLATIONS[key]:
                        data["GLOBAL"][key] = USER_TRANSLATIONS[key][lang_code]
                        added_count += 1

        # Add SETTINGS_SECURITY section entries (except de, nl, ru)
        if lang_code not in SKIP_REMEMBER_LANGS:
            if "SETTINGS_SECURITY" not in data:
                data["SETTINGS_SECURITY"] = {}

            if "LABEL_STORED_PASS" not in data["SETTINGS_SECURITY"]:
                if lang_code in USER_TRANSLATIONS["LABEL_STORED_PASS"]:
                    data["SETTINGS_SECURITY"]["LABEL_STORED_PASS"] = USER_TRANSLATIONS["LABEL_STORED_PASS"][lang_code]
                    added_count += 1

        # Add MESSAGE_LIST section entries (cs only)
        if lang_code == "cs":
            if "MESSAGE_LIST" not in data:
                data["MESSAGE_LIST"] = {}

            for key in ["SORT_ARRIVAL_ASC", "SORT_ARRIVAL_DESC"]:
                if key not in data["MESSAGE_LIST"]:
                    data["MESSAGE_LIST"][key] = USER_TRANSLATIONS[key].get(lang_code, "")
                    added_count += 1

        # Write back
        if added_count > 0:
            with open(user_file, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
                f.write('\n')  # Ensure newline at end
            print(f"  {lang_code}: added {added_count} keys")
        else:
            print(f"  {lang_code}: all keys present")


if __name__ == "__main__":
    patch_admin_files()
    patch_user_files()
    print("\nDone!")
