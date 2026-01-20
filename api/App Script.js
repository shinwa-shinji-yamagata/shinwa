function handleEdit(e) {
  const range = e.range;
  const sheet = e.source.getActiveSheet();
  const sheetName = sheet.getName();
  const row = range.getRow();
  const col = range.getColumn();
  const inputValue = e.value; // 編集後の値（削除時は undefined）
  const oldValue = e.oldValue || '';
  const newValue = inputValue || '';

  if (row < 5 || row > 300) return;

  if (!inputValue) {
    range.clearDataValidations();
    range.setComment('');
    if (col === 3) {
      sheet.getRange(row, 4).clearContent();
      sheet.getRange(row, 4).clearDataValidations();
      sheet.getRange(row, 4).setComment('');
    }
    // 削除時もログ送信したいので return せず続行
  }

  const token = 'your_secret_token';
  const endpoint = 'https://shinwa1.com/api/autocomplete.php';

  // --- ログ送信処理 ---
  try {
    if (!sheetName.includes("月集計")) {
      const cellA1 = range.getA1Notation();
      const message = `「${e.source.getName()}${sheetName}」「${cellA1}」：「${oldValue}」→「${newValue}」`;

      const userEmail = Session.getActiveUser().getEmail();

      const logUrl = 'https://shinwa1.com/ss_log/ss_log.php';
      const payload = {
        program: 'スプレッドシート',
        message: message,
        google_account: userEmail,
        token: token
      };

      UrlFetchApp.fetch(logUrl, {
        method: 'post',
        payload: payload,
        muteHttpExceptions: true
      });
    }
  } catch (err) {
    console.error('ログ送信失敗', err);
  }
  // --- ログ送信ここまで ---

  // C列（name/furigana/romaji）を編集したとき
  if (col === 3) {
    const targetCell = sheet.getRange(row, 3);
    const subjectCell = sheet.getRange(row, 4);

    if (!inputValue) {
      targetCell.clearDataValidations();
      subjectCell.clearContent();
      subjectCell.clearDataValidations();
      return;
    }

    const url = `${endpoint}?query=${encodeURIComponent(inputValue)}&field=name&token=${token}`;
    try {
      const response = UrlFetchApp.fetch(url);
      const suggestions = JSON.parse(response.getContentText());
      if (suggestions.length > 0) {
        if (suggestions.includes('該当なし')) {
          targetCell.clearDataValidations();
          targetCell.setComment('候補が見つかりません');
        } else {
          const rule = SpreadsheetApp.newDataValidation()
            .requireValueInList(suggestions, false)
            .setAllowInvalid(true)
            .build();
          targetCell.setDataValidation(rule);
          targetCell.setComment('');
          targetCell.setBackground(null);
        }
      } else {
        targetCell.clearDataValidations();
        targetCell.setComment('候補が見つかりません');
      }
      if (suggestions.includes(inputValue)) {
        const subjectUrl = `${endpoint}?query=${encodeURIComponent(inputValue)}&field=subject&token=${token}`;
        const subjectRes = UrlFetchApp.fetch(subjectUrl);
        const subjectList = JSON.parse(subjectRes.getContentText());
        if (subjectList.length > 0) {
          if (subjectList.includes('該当なし')) {
            const subjectRule = SpreadsheetApp.newDataValidation()
              .requireValueInList(['該当なし'], true)
              .setAllowInvalid(true)
              .build();
            subjectCell.setDataValidation(subjectRule);
          } else {
            const subjectRule = SpreadsheetApp.newDataValidation()
              .requireValueInList(subjectList, false)
              .setAllowInvalid(true)
              .build();
            subjectCell.setDataValidation(subjectRule);
          }
        } else {
          subjectCell.clearDataValidations();
        }
      } else {
        subjectCell.clearDataValidations();
      }
    } catch (error) {
      targetCell.clearDataValidations();
      subjectCell.clearDataValidations();
    }
  }

  // D列（subject）を編集したとき
  if (col === 4) {
    const nameValue = sheet.getRange(row, 3).getValue();
    const subjectCell = sheet.getRange(row, 4);
    if (!nameValue) {
      subjectCell.clearDataValidations();
      return;
    }
    const url = `${endpoint}?query=${encodeURIComponent(nameValue)}&field=subject&token=${token}`;
    try {
      const response = UrlFetchApp.fetch(url);
      const suggestions = JSON.parse(response.getContentText());
      if (suggestions.length > 0) {
        if (suggestions.includes('該当なし')) {
          const rule = SpreadsheetApp.newDataValidation()
            .requireValueInList(['該当なし'], true)
            .setAllowInvalid(true)
            .build();
          subjectCell.setDataValidation(rule);
        } else {
          const rule = SpreadsheetApp.newDataValidation()
            .requireValueInList(suggestions, false)
            .setAllowInvalid(true)
            .build();
          subjectCell.setDataValidation(rule);
        }
      } else {
        subjectCell.clearDataValidations();
      }
    } catch (error) {
      subjectCell.clearDataValidations();
    }
  }
}
