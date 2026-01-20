<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_user = wp_get_current_user();
$allowed_roles = ['keiri', 'soumu', 'administrator'];
$can_upload = array_intersect($allowed_roles, $current_user->roles);
$is_allowed = !empty($can_upload);

// ボタンの活性状態とメッセージを決定
$buttonDisabled = (!$is_allowed) ? 'disabled' : '';
$statusMessage = '';
if (!$is_allowed) {
    $statusMessage = '権限がありません';
}
?>

  <style>
  .drop-area {
    border: 2px dashed #999;
    border-radius: 10px;
    padding: 40px;
    text-align: center;
    color: #666;
    background-color: #f9f9f9;
    cursor: pointer;
    transition: background-color 0.3s;
  }
  .drop-area.dragover {
    background-color: #e0f7ff;
    border-color: #00aaff;
    color: #0077aa;
  }
  .drop-area input[type="file"] {
    display: none;
  }
  .file-label {
    display: inline-block;
    margin-top: 10px;
    padding: 8px 16px;
    background-color: #0077aa;
    color: #fff;
    border-radius: 4px;
    cursor: pointer;
  }
  .submit-button {
    margin-top: 20px;
    padding: 10px 20px;
    font-size: 16px;
  }
  #file-list {
    margin-top: 15px;
    list-style: none;
    padding: 0;
    font-size: 14px;
    color: #333;
  }
  </style>
  <div id="seikyu-uploader">
    <h2>請求書ファイルをアップロード</h2>
    <form method="POST" enctype="multipart/form-data" action="/post/seikyu_upload.php" id="upload-form">
      <div class="drop-area" id="drop-area">
        <p>ここにExcelファイルをドラッグ＆ドロップ</p>
        <label class="file-label">
          ファイルを選択
          <input type="file" name="excels[]" id="file-input" multiple accept=".xlsx">
        </label>
        <ul id="file-list"></ul>
      </div>
      <button type="submit" class="submit-button" <?= $buttonDisabled ?>>アップロードして処理開始</button>
      <div id="statusMessageUpload"><?= $statusMessage ?></div>
    </form>
  </div>
  
<script>
  let droppedFiles = null;

  const dropArea = document.getElementById('drop-area');
  const fileInput = document.getElementById('file-input');
  const fileList = document.getElementById('file-list');

  // ファイル一覧を更新
  function updateFileList(fileListObj) {
    fileList.innerHTML = '';
    if (!fileListObj || fileListObj.length === 0) {
      const li = document.createElement('li');
      li.textContent = 'ファイルが選択されていません';
      fileList.appendChild(li);
      return;
    }

    for (let i = 0; i < fileListObj.length; i++) {
      const li = document.createElement('li');
      li.textContent = fileListObj[i].name;
      fileList.appendChild(li);
    }
  }

  // ドラッグオーバー時のスタイル変更
  dropArea.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropArea.classList.add('dragover');
  });

  dropArea.addEventListener('dragleave', () => {
    dropArea.classList.remove('dragover');
  });

  // ドロップされたファイルを input にセット
  dropArea.addEventListener('drop', (e) => {
    e.preventDefault();
    dropArea.classList.remove('dragover');

    droppedFiles = e.dataTransfer.files;
    updateFileList(droppedFiles);
  });

  // ファイル選択ボタンで選んだとき
  fileInput.addEventListener('change', () => {
    updateFileList(fileInput.files);
  });

  document.getElementById('upload-form').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData();
    const files = droppedFiles && droppedFiles.length ? droppedFiles : fileInput.files;

    if (!files || files.length === 0) {
      alert('ファイルが選択されていません');
      return;
    }

    for (let i = 0; i < files.length; i++) {
      formData.append('excels[]', files[i]);
    }

    fetch('/post/seikyu_upload.php', {
      method: 'POST',
      body: formData
    })
    .then(response => {
      if (!response.ok) throw new Error('アップロードに失敗しました');
      return response.json();
    })
    .then(data => {
      if (data.redirect) {
        window.location.href = data.redirect;
      } else {
        alert('リダイレクト先が見つかりませんでした');
      }
    })
    .catch(err => {
      alert(err.message);
    });
  });

</script>
