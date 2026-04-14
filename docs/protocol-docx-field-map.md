# Protocol DOCX Field Map

Source template:
- `C:\Users\AB\Downloads\(Protocol) БиОТ ИТР.docx`

Purpose:
- this file records the dynamic red placeholders present in the editable Word template
- it is the authoritative field inventory for rebuilding the current `engineer_protocol` renderer against the DOCX source instead of guessing from the PDF overlay

## Current mapped placeholders
1. `protocolnumber`
- Paragraph: `Хаттамасы / Протокол № 00`
- Red text: `00`
- Expected format: `PRO-UserID-XXXX-YYYYMMDD-ZZZZ`

2. `clientcompanyname`
- Paragraph: `ТОО "Электровоз құрастыру зауыты"`
- Red text: `ТОО "Электровоз құрастыру зауыты"`
- Appears in:
  - company header row
  - learner table organisation column

3. `issuedatekz` + `issuedateru`
- Paragraph: `2026 жылғы "25" ақпан / "25" февраля 2026 года`
- Red text: `2026 жылғы "25" ақпан "25" февраля 2026 года`
- Render rule:
  - left segment is KZ date
  - right segment is RU date

4. `chairfull`
- Paragraph: `Аубикеров Т.К. - директор ТОО "SENTAL"`
- Red text: `Аубикеров Т.К. - директор ТОО "SENTAL"`

5. `member1full`
- Paragraph: `Амиржанова Г.Ж. - инструктор ТОО "SENTAL"`
- Red text: `Амиржанова Г.Ж. - инструктор ТОО "SENTAL"`

6. `member2full`
- Paragraph: `Мухтаров А.Г. - координатор по обучению ТОО "SENTAL"`
- Red text: `Мухтаров А.Г. - координатор по обучению ТОО "SENTAL"`

7. `orderkz` + `orderru`
- Paragraph: `2025 жылғы "22" қазан №-2025-03 бұйрықтың негізінде / На основании приказа от "22" октября 2025 года №-2025-03`
- Red text: `2025 жылғы "22" қазан №-2025-03 "22" октября 2025 года №-2025-03`
- Render rule:
  - left segment is KZ order reference
  - right segment is RU order reference

8. `protocoltypekz` + `protocoltyperu`
- Paragraph: `білімін тексеру түрі (қайталама) / вид проверки знаний (повторный)`
- Red text: `қайталама` and `повторный`

9. `userfullname`
- Paragraph: `Иванов Иван Иваныч`
- Red text: `Иванов Иван Иваныч`

10. `companytable`
- Paragraph: `ТОО "Электровоз құрастыру зауыты"`
- Red text: `ТОО "Электровоз құрастыру зауыты"`

11. `userjobtitle`
- Paragraph: `Инженер`
- Red text: `Инженер`

12. `completionstatus`
- Paragraph: `өтті / прошел`
- Red text: `өтті / прошел`

13. `certificatenumber`
- Paragraph: `SEN-2026.034`
- Red text: `SEN-2026.034`

14. `chairinitials`
- Paragraph: `Аубикеров Т.К.`
- Red text: `Аубикеров Т.К.`

15. `member1initials`
- Paragraph: `Амиржанова Г.Ж.`
- Red text: `Амиржанова Г.Ж.`

16. `member2initials`
- Paragraph: `Мухтаров А.Г.`
- Red text: `Мухтаров А.Г.`

## Static template decisions confirmed by the DOCX
- top legal entity line `_ __ ТОО "SENTAL" ___` is static template text
- black headings and black helper text remain part of the fixed template
- red placeholder text must disappear in the final output
- `м.о. / м.п.` is still present in the Word template as static text, but the client has already requested that it be removed from the final output path

## Practical use
- use this file to validate whether a PDF field in `document_generator.php` corresponds to a real placeholder from the editable template
- if a field is not represented here, it should not be introduced into the output without confirming it exists in the Word source
