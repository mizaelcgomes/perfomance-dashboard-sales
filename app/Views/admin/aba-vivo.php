<?php /** @var \App\Core\View $v  Aba vivo do admin: o HTML que antes ficava no admin.php (fase 7). */ ?>
  <!-- ═══════════════ AO VIVO (rotação, música, pop-up, cerimônia) ═══════════════ -->
  <div class="tab-pane" id="tabVivo">
    <div class="card">
      <div class="card-title">Rotação do Painel</div>
      <p style="font-size:12px;color:rgba(255,255,255,0.45);margin-bottom:14px;">Define a ordem e o tempo (em segundos) de cada item exibido no painel principal. Se vazio, todos os pódios são exibidos por 60 s cada. Os itens de <b>Retrospectiva</b> ocupam a tela inteira (Hall da Fama) com os 3 melhores closers e a melhor equipe da semana ou do mês que acabou de fechar; se o período não tiver contratos, o item é pulado sozinho.</p>
      <div id="rotationList"></div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;align-items:center;">
        <select id="addRotSelect" class="inp" style="flex:1;min-width:200px;">
          <option value="">— Escolher item para adicionar —</option>
          <optgroup label="Pódio">
            <option value="podium:semanal">Pódio Semanal — Closers</option>
            <option value="podium:geral">Pódio Mensal — Closers</option>
            <option value="podium:equipe_semanal">Pódio Semanal — Equipes</option>
            <option value="podium:equipe_mensal">Pódio Mensal — Equipes</option>
          </optgroup>
          <optgroup label="Retrospectiva — Hall da Fama (tela cheia)">
            <option value="podium:retro_semana">Retrospectiva da Semana Anterior — Top 3 + Melhor Equipe</option>
            <option value="podium:retro_mes">Retrospectiva do Mês Anterior — Top 3 + Melhor Equipe</option>
          </optgroup>
          <optgroup label="Propaganda" id="addRotPropGroup"></optgroup>
        </select>
        <button class="btn btn-s" onclick="addRotationItem()">+ Adicionar</button>
        <button class="btn btn-p" onclick="saveRotation()" style="margin-left:auto;">Salvar Rotação</button>
      </div>
    </div>

    <div class="card">
      <div class="card-title">Música de Fundo</div>
      <p style="font-size:12px;color:rgba(255,255,255,0.45);margin-bottom:14px;">Músicas tocadas continuamente no painel. A música pausa durante o pop-up de celebração e volta depois. Somente arquivos MP3, máx 25 MB cada.</p>
      <div id="bgMusicList" style="margin-bottom:14px;"></div>
      <form id="bgMusicForm" onsubmit="return uploadBgMusic(event)" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <label class="btn btn-s" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
          Escolher MP3
          <input type="file" id="bgMusicFile" accept=".mp3,audio/mpeg" style="display:none" onchange="document.getElementById('bgMusicFileName').textContent=this.files[0]?.name||''">
        </label>
        <span id="bgMusicFileName" style="font-size:12px;color:rgba(255,255,255,0.5);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
        <button type="submit" class="btn btn-p">Enviar</button>
      </form>
    </div>

    <div class="section-divider"><span>Disparos Manuais</span></div>

    <div class="card">
      <div class="card-title">Pop-up Personalizado</div>
      <p style="font-size:12px;color:rgba(255,255,255,0.45);margin-bottom:14px;">Cadastre um título e até duas fotos, depois clique em "Disparar agora" sempre que quiser exibir no painel. Some sozinho depois de alguns segundos.</p>
      <form id="cpForm" onsubmit="return submitCustomPopup(event)">
        <div class="fgrid">
          <div class="fg" style="grid-column:1/-1"><label class="lbl">Título / mensagem</label><input class="inp" id="cpTitulo" placeholder="Ex: Bem-vindos à equipe!"></div>
          <div class="fg">
            <label class="lbl">Foto 1</label>
            <div class="upload-wrap" id="cpUploadArea1" onclick="document.getElementById('cpFoto1').click()">
              <input type="file" style="display:none" id="cpFoto1" accept="image/*" onchange="previewImg(event,'cpPreviewImg1','cpUploadArea1','cpPlaceholder1','cpPreviewWrap1')">
              <div id="cpPlaceholder1"><div style="font-size:10px;color:#f0d878">Clique para enviar</div><div style="font-size:9px;color:rgba(212,175,55,.45)">JPG, PNG ou GIF (máx 500KB)</div></div>
              <div id="cpPreviewWrap1" style="display:none;position:relative"><div class="up-preview"><img id="cpPreviewImg1" src=""><div class="rm-btn" onclick="event.stopPropagation();removeImg('cpFoto1','cpUploadArea1','cpPlaceholder1','cpPreviewWrap1')"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></div></div></div>
            </div>
          </div>
          <div class="fg">
            <label class="lbl">Foto 2</label>
            <div class="upload-wrap" id="cpUploadArea2" onclick="document.getElementById('cpFoto2').click()">
              <input type="file" style="display:none" id="cpFoto2" accept="image/*" onchange="previewImg(event,'cpPreviewImg2','cpUploadArea2','cpPlaceholder2','cpPreviewWrap2')">
              <div id="cpPlaceholder2"><div style="font-size:10px;color:#f0d878">Clique para enviar</div><div style="font-size:9px;color:rgba(212,175,55,.45)">JPG, PNG ou GIF (máx 500KB)</div></div>
              <div id="cpPreviewWrap2" style="display:none;position:relative"><div class="up-preview"><img id="cpPreviewImg2" src=""><div class="rm-btn" onclick="event.stopPropagation();removeImg('cpFoto2','cpUploadArea2','cpPlaceholder2','cpPreviewWrap2')"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></div></div></div>
            </div>
          </div>
        </div>
        <div class="fa">
          <button type="submit" class="btn btn-s">Salvar</button>
          <button type="button" class="btn btn-p" onclick="triggerCustomPopup()">Disparar agora</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
        <div class="card-title" style="margin-bottom:0">Cerimônia de Metas <small id="ceremWeekLbl"></small></div>
        <button class="btn btn-s btn-sm" onclick="loadCeremonyPreview()">Atualizar</button>
      </div>
      <p style="font-size:12px;color:rgba(255,255,255,0.45);margin-bottom:14px;">Homenageia, um por um, todos os closers que bateram a meta semanal na semana escolhida (por padrão, a anterior). No final, todos aparecem juntos em uma grade. Dispare no dia da solenidade de entrega de metas.</p>
      <div class="fg" style="margin-bottom:12px"><label class="lbl" for="ceremWeek">Semana</label><select class="sel" id="ceremWeek" onchange="loadCeremonyPreview(this.value)"></select></div>
      <div id="ceremList" style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px;"></div>
      <div class="fa" style="margin-bottom:16px;">
        <button type="button" class="btn btn-p" id="ceremTriggerBtn" onclick="triggerCeremony()">Disparar cerimônia</button>
      </div>
      <div style="border-top:1px solid rgba(212,175,55,.1);padding-top:14px;">
        <div class="fgrid">
          <div class="fg">
            <label class="lbl">Duração da tela final com todos juntos (segundos)</label>
            <input type="number" class="inp" id="ceremFinalDuration" min="3" max="300" value="15">
          </div>
          <div class="fg">
            <label class="lbl">Música da cerimônia (MP3)</label>
            <label class="btn btn-s" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;width:fit-content;">
              Escolher MP3
              <input type="file" id="ceremMusicFile" accept=".mp3,audio/mpeg" style="display:none" onchange="document.getElementById('ceremMusicFileName').textContent=this.files[0]?.name||''">
            </label>
            <span id="ceremMusicFileName" style="font-size:11px;color:rgba(255,255,255,0.5);display:block;margin-top:6px;"></span>
          </div>
        </div>
        <div class="fa">
          <button type="button" class="btn btn-s" onclick="saveCeremonySettings()">Salvar configurações</button>
        </div>
      </div>
    </div>
  </div>

