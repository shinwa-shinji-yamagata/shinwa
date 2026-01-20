<h2 style="margin-bottom: 0;">新和なんでもAIチャットボットくん２号</h2>
<p style="font-size:13px;">追加学習させるには <font color="royalblue">\\Shinwa-srv2025\\現場\\個人\\晋二\\chatbot</font> に文書(テキスト、Word、Excel、PowerPoint、PDF)を設置<br>
山形（晋）が中に入っているファイルを確認次第、学習させます。
<button id="copy-btn" onclick="navigator.clipboard.writeText('\\\\Shinwa-srv2025\\現場\\個人\\晋二\\chatbot')" style="font-size:13px; padding-top:6px; padding-bottom:6px;">
パスをコピー
</button><span id="copy-msg" style="font-size:13px; margin-left:10px; color:green; display:none;">コピーしました！</span><br>
</p>
<div id="sw-chatbot">
  <div id="sw-chat-body" class="sw-chat-body"></div>
  <div class="sw-chat-input-wrap">
    <textarea id="sw-chat-input" class="sw-chat-input" rows="2" placeholder="新和についてなんでも質問してみてね！
答えられないのも沢山あるよ！"></textarea>
    <button id="sw-chat-send" class="sw-chat-send">送信</button>
  </div>
</div>

<style>
@media screen and (max-width: 768px) {
  #sw-chatbot {
    padding: 8px;
  }

  .sw-chat-input-wrap {
    flex-direction: column;
    align-items: stretch;
  }

  .sw-chat-input {
    width: 100%;
    font-size: 16px;
    -webkit-text-size-adjust: 100%;
  }

  .sw-chat-send {
    width: 100%;
    margin-top: 6px;
    height: 44px;
    font-size: 16px;
  }

  #copy-btn {
    width: 100%;
    margin-top: 6px;
  }

  #copy-msg {
    display: block;
    margin-top: 4px;
    margin-left: 0;
  }
}
  #sw-chatbot { border: 1px solid #e3e3e3; border-radius: 10px; padding: 12px; background: #fff; max-width: 1600px; margin: 0 auto; font-family: system-ui, -apple-system, Segoe UI, Roboto, "Hiragino Kaku Gothic ProN", "Yu Gothic", Meiryo, sans-serif; }
  .sw-chat-header { font-weight: 600; font-size: 16px; margin-bottom: 8px; }
.sw-chat-body {
  border: 1px solid #eee;
  border-radius: 8px;
  padding: 10px;
  min-height: 300px;
  /* height: 360px;  ← 固定高さを削除 */
  /* overflow-y: auto; ← 内部スクロールを削除 */
  background: #fafafa;
}
  .sw-msg { margin: 10px 0; display: flex; gap: 8px; align-items: flex-start; }
  .sw-msg .bubble { padding: 8px 10px; border-radius: 10px; max-width: 80%; line-height: 1.6; white-space: pre-wrap; }
  .sw-msg.user .bubble { background: #e9f3ff; border: 1px solid #cfe6ff; margin-left: auto; }
  .sw-msg.bot .bubble { background: #F0FFF0; border: 1px solid #e6e6e6; }
  .sw-msg.bot.loading .bubble { position: relative; }
  .sw-msg.bot.loading .bubble::after { content: "…"; margin-left: 4px; animation: sw-dots 1s steps(3, end) infinite; }
  @keyframes sw-dots { 0% { content: ""; } 33% { content: "."; } 66% { content: ".."; } 100% { content: "..."; } }

  .sw-sources { margin-top: 6px; font-size: 12px; color: #666; }
  .sw-source-item { display: inline-block; background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 4px 6px; margin: 3px 4px 0 0; }

.sw-chat-input-wrap {
  display: flex;
  gap: 8px;
  margin-top: 10px;
  align-items: center; /* ← 送信ボタンを縦中央に揃える */
}

.sw-chat-input {
  flex: 1;
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 10px 12px; /* ← 少し余白を広げて行間を調整 */
  line-height: 1.6;    /* ← 行間を広げる */
  resize: vertical;
  font-size: 16px;     /* ← 必要ならフォントサイズも調整 */
}

.sw-chat-send {
  border: none;
  background: #2c79ff;
  color: #fff;
  padding: 10px 16px;  /* ← 高さを固定しつつ、見た目を調整 */
  margin-bottom: 8px;
  border-radius: 8px;
  cursor: pointer;
  height: 40px;        /* ← 高さを固定 */
  display: flex;
  align-items: center;
  justify-content: center;
}
  .sw-chat-send:disabled { opacity: 0.6; cursor: not-allowed; }
</style>

<script>
(function() {
  const bodyEl  = document.getElementById('sw-chat-body');
  const inputEl = document.getElementById('sw-chat-input');
  const sendEl  = document.getElementById('sw-chat-send');

  // メッセージ追加
  function addMessage(text, role, temporary = false) {
    const id = temporary ? String(Date.now() + Math.random()) : null;
    const msg = document.createElement('div');
    msg.className = `sw-msg ${role}` + (temporary && role === 'bot' ? ' loading' : '');
    if (id) msg.dataset.id = id;

    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    bubble.textContent = text;

    msg.appendChild(bubble);
    bodyEl.appendChild(msg);
    bodyEl.scrollTop = bodyEl.scrollHeight;

    return id;
  }

  // メッセージ更新
  function updateMessage(id, newText) {
    if (!id) return;
    const msg = bodyEl.querySelector(`.sw-msg[data-id="${id}"]`);
    if (!msg) return;
    msg.classList.remove('loading');
    const bubble = msg.querySelector('.bubble');
    bubble.textContent = newText;
    bodyEl.scrollTop = bodyEl.scrollHeight;
  }

  // 参考文献表示
  function addSources(sources) {
    if (!Array.isArray(sources) || sources.length === 0) return;
    const wrap = document.createElement('div');
    wrap.className = 'sw-sources';
    wrap.textContent = '参照した文書: ';
    sources.forEach(s => {
      const tag = document.createElement('span');
      tag.className = 'sw-source-item';
      const title = (s.title || '').slice(0, 40);
      const section = s.section ? ` / ${s.section}` : '';
      const page = s.page ? ` p${s.page}` : '';
      const score = typeof s.score === 'number' ? ` (${s.score})` : '';
      tag.textContent = `${title}${section}${page}${score}`;
      wrap.appendChild(tag);
    });
    bodyEl.appendChild(wrap);
    bodyEl.scrollTop = bodyEl.scrollHeight;
  }

  async function sendMessage() {
    const q = (inputEl.value || '').trim();
    if (!q) return;

    addMessage(q, 'user');
    inputEl.value = '';
    inputEl.disabled = true;
    sendEl.disabled = true;

    const loadingId = addMessage('AIボットくんが頑張ってます', 'bot', true);

    try {
      const res = await fetch('/chatbot/chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'q=' + encodeURIComponent(q)
      });

      if (!res.ok) {
        const text = await res.text().catch(() => '');
        updateMessage(loadingId, `エラー (HTTP ${res.status}): ${text || '不明なエラー'}`);
        return;
      }

      let data;
      try {
        data = await res.json();
      } catch (e) {
        const raw = await res.text().catch(() => '');
        updateMessage(loadingId, `レスポンス解析失敗: ${String(e)}\n\n${raw}`);
        return;
      }

      if (data && data.error) {
        updateMessage(loadingId, `エラー: ${data.error}`);
        return;
      }

      updateMessage(loadingId, (data && data.answer) ? data.answer : '回答が取得できませんでした。');
      if (data && data.sources) addSources(data.sources);

    } catch (e) {
      updateMessage(loadingId, 'エラー: ' + String(e));
    } finally {
      inputEl.disabled = false;
      sendEl.disabled = false;
      inputEl.focus();
    }
  }

  sendEl.addEventListener('click', sendMessage);
  inputEl.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });
})();
  document.getElementById('copy-btn').addEventListener('click', () => {
    const path = '\\\\Shinwa-srv2025\\現場\\個人\\晋二\\chatbot';
    navigator.clipboard.writeText(path).then(() => {
      const msg = document.getElementById('copy-msg');
      msg.style.display = 'inline';
      setTimeout(() => {
        msg.style.display = 'none';
      }, 2000); // 2秒後にメッセージを消す
    }).catch(err => {
      alert('コピーに失敗しました: ' + err);
    });
  });
</script>
