<?php /** @var \App\Core\View $v  Aba propaganda do admin: o HTML que antes ficava no admin.php (fase 7). */ ?>
  <!-- ═══════════════ PROPAGANDA ═══════════════ -->
  <div class="tab-pane" id="tabPropaganda">
    <div class="card">
      <div class="card-title" id="propFormTitle">Nova Propaganda</div>
      <form id="propForm" onsubmit="return submitPropaganda(event)">
        <input type="hidden" id="propId">
        <div class="fgrid">
          <div class="fg"><label class="lbl">Título</label><input class="inp" id="propTitulo" placeholder="Identificação (ex: Promo Black Friday)"></div>
          <div class="fg" style="align-items:center;justify-content:center;padding-top:18px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" id="propAtivo" checked style="width:16px;height:16px;accent-color:#d4af37;">
              <span class="lbl" style="margin:0">Ativa</span>
            </label>
          </div>
        </div>
        <div class="fg" style="margin-bottom:14px;">
          <label class="lbl">Código HTML</label>
          <textarea class="inp prop-html-area" id="propHtml" placeholder="Cole aqui o HTML da propaganda..." required></textarea>
        </div>
        <div class="fa">
          <button type="submit" class="btn btn-p" id="propSubmitBtn">Adicionar Propaganda</button>
          <button type="button" class="btn btn-s" id="propCancelBtn" onclick="resetPropForm()" style="display:none">Cancelar</button>
        </div>
      </form>
    </div>
    <div class="card">
      <div class="card-title">Propagandas Cadastradas <small>(<span id="propCount">0</span>)</small></div>
      <div id="propList"></div>
    </div>
  </div>

