function handleEdit(e) {
  const sheet = e.source.getActiveSheet();
  const range = e.range;
  const row = range.getRow();
  const col = range.getColumn();

  // 編集後の値（貼り付け時 undefined の場合あり）
  const inputValue = e.value;
  const oldValue = e.oldValue || "";

  // 対象範囲外は無視
  if (row < 5 || row > 300) return;

  // ------------------------------------------------------------
  // 1. 削除判定（貼り付け時の undefined を削除扱いにしない）
  // ------------------------------------------------------------
  const isDelete =
    inputValue === undefined &&        // e.value が undefined
    oldValue !== "" &&                 // 以前は値があった
    range.getValue() === "";           // セルが実際に空になっている

  // ------------------------------------------------------------
  // 2. 全角 → 半角変換（F〜K列）
  // ------------------------------------------------------------
  if (col >= 6 && col <= 11 && inputValue) {
    const converted = inputValue
      .replace(/[０-９]/g, s => String.fromCharCode(s.charCodeAt(0) - 0xFEE0))
      .replace(/[．。]/g, ".");

    if (converted !== inputValue) {
      range.setValue(converted);
    }
  }

  // ------------------------------------------------------------
  // 3. 削除時の処理（C列削除 → D列もクリア）
  // ------------------------------------------------------------
  if (isDelete) {
    range.clearDataValidations();
    range.setComment("");
/*
    if (col === 3) {
      const subjectCell = sheet.getRange(row, 4);
      subjectCell.clearContent();
      subjectCell.clearDataValidations();
      subjectCell.setComment("");
    }
*/
  }

  // ------------------------------------------------------------
  // 4. API 設定
  // ------------------------------------------------------------
  const token = "your_secret_token";
  const endpoint = "https://shinwa1.com/api/autocomplete.php";

  // ------------------------------------------------------------
  // 5. C列（名前）編集時の処理
  // ------------------------------------------------------------
  if (col === 3 && !isDelete) {
    const nameCell = sheet.getRange(row, 3);
    const subjectCell = sheet.getRange(row, 4);

    // 空文字（手入力で削除）なら D列クリア
    if (inputValue === "") {
      nameCell.clearDataValidations();
      subjectCell.clearContent();
      subjectCell.clearDataValidations();
      return;
    }

    // API 呼び出し
    const url = `${endpoint}?query=${encodeURIComponent(inputValue)}&field=name&token=${token}`;

    try {
      const res = UrlFetchApp.fetch(url);
      const suggestions = JSON.parse(res.getContentText());

      // バリデーション設定
      if (suggestions.length === 0 || suggestions.includes("該当なし")) {
        nameCell.clearDataValidations();
        nameCell.setComment("候補が見つかりません");
      } else {
        const rule = SpreadsheetApp.newDataValidation()
          .requireValueInList(suggestions, false)
          .setAllowInvalid(true)
          .build();
        nameCell.setDataValidation(rule);
        nameCell.setComment("");
      }

      // 入力値が候補に一致 → D列の候補を取得
      if (suggestions.includes(inputValue)) {
        const subjectUrl = `${endpoint}?query=${encodeURIComponent(inputValue)}&field=subject&token=${token}`;
        const subjectRes = UrlFetchApp.fetch(subjectUrl);
        const subjectList = JSON.parse(subjectRes.getContentText());

        if (subjectList.length === 0) {
          subjectCell.clearDataValidations();
        } else {
          const rule = SpreadsheetApp.newDataValidation()
            .requireValueInList(subjectList, subjectList.includes("該当なし"))
            .setAllowInvalid(true)
            .build();
          subjectCell.setDataValidation(rule);
        }
      } else {
        subjectCell.clearDataValidations();
      }
    } catch (err) {
      nameCell.clearDataValidations();
      subjectCell.clearDataValidations();
    }
  }

  // ------------------------------------------------------------
  // 6. D列（科目）編集時の処理
  // ------------------------------------------------------------
  if (col === 4 && !isDelete) {
    const nameValue = sheet.getRange(row, 3).getValue();
    const subjectCell = sheet.getRange(row, 4);

    if (!nameValue) {
      subjectCell.clearDataValidations();
      return;
    }

    const url = `${endpoint}?query=${encodeURIComponent(nameValue)}&field=subject&token=${token}`;

    try {
      const res = UrlFetchApp.fetch(url);
      const suggestions = JSON.parse(res.getContentText());

      if (suggestions.length === 0) {
        subjectCell.clearDataValidations();
      } else {
        const rule = SpreadsheetApp.newDataValidation()
          .requireValueInList(suggestions, suggestions.includes("該当なし"))
          .setAllowInvalid(true)
          .build();
        subjectCell.setDataValidation(rule);
      }
    } catch (err) {
      subjectCell.clearDataValidations();
    }
  }
}
