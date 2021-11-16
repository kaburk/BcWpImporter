<?php echo $this->BcForm->create('BcWpImporter', ['type' => 'file']) ?>

<section class="bca-section">
	<table id="FormTable" class="form-table bca-form-table">
		<tbody>
			<tr>
				<th class="col-head bca-form-table__label" width="25%">
					<?php echo $this->BcForm->label('BcWpImporter.blog_content_id', 'ブログの指定') ?>
				</th>
				<td class="col-input bca-form-table__input">
					<?php echo $this->BcForm->input('BcWpImporter.blog_content_id', [
						'type' => 'select', 'options' => $blogContents,
					]) ?>
					<?php echo $this->BcForm->error('BcWpImporter.blog_content_id') ?>
					<br /><small>インポート先のブログを指定できます</small>
				</td>
			</tr>
			<tr>
				<th class="col-head bca-form-table__label" width="25%">
					<?php echo $this->BcForm->label('BcWpImporter.clear_data', 'ブログ記事の初期化') ?>
				</th>
				<td class="col-input bca-form-table__input">
					<?php echo $this->BcForm->input('BcWpImporter.clear_data', [
						'type' => 'checkbox', 'label' => 'インポート前にブログ記事を初期化する',
					]) ?>
					<?php echo $this->BcForm->error('BcWpImporter.clear_data') ?>
					<br /><small>インポート前にインポート先のブログ記事を初期化する場合はチェックを入れてください。</small>
					<br /><small>初期化を指定すると、選択したブログの記事を削除した上で、AUTO_INCREMENTの値を、次のデータが最大値になるように調整します。</small>
				</td>
			</tr>
			<tr>
				<th class="col-head bca-form-table__label" width="25%">
					<?php echo $this->BcForm->label('BcWpImporter.file', 'アップロード') ?>
				</th>
				<td class="col-input bca-form-table__input">
					<?php echo $this->BcForm->file('BcWpImporter.file', ['type' => 'file']) ?>
					<?php echo $this->BcForm->error('BcWpImporter.file') ?>
					<br /><small>WordPress管理画面よりエクスポートしたXMLファイルを選択してください。</small>
				</td>
			</tr>
		</tbody>
	</table>
</section>

<section class="bca-actions">
	<div class="bca-actions__main">
		<?php echo $this->BcForm->submit('アップロード', [
			'id' => 'BtnSave',
			'div' => false,
			'class' => 'button bca-btn bca-actions__item',
			'data-bca-btn-type' => 'save',
			'data-bca-btn-size' => 'lg',
			'data-bca-btn-width' => 'lg',
		]) ?>
	</div>
</section>

<?php echo $this->BcForm->end() ?>
