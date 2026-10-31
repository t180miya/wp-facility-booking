( function () {
	'use strict';

	document.querySelectorAll( '.wpplugin-facility-manager' ).forEach( ( manager ) => {
		const dataInput = manager.querySelector( '.wpplugin-facility-manager__data' );
		const rows = manager.querySelector( '.wpplugin-facility-manager__rows' );
		const dialog = manager.querySelector( '.wpplugin-facility-manager__dialog' );
		const form = manager.querySelector( '.wpplugin-facility-manager__form' );
		const title = manager.querySelector( '.wpplugin-facility-manager__dialog-title' );
		const notice = manager.querySelector( '.wpplugin-facility-manager__notice' );
		const emptyState = manager.querySelector( '.wpplugin-facility-manager__empty' );
		const editButton = manager.querySelector( '[data-action="edit"]' );
		const deleteButton = manager.querySelector( '[data-action="delete"]' );
		let facilities = [];
		let selectedId = '';

		try {
			facilities = JSON.parse( dataInput.value || '[]' );
		} catch ( error ) {
			facilities = [];
		}

		const fields = [
			[ 'id', '施設ID' ],
			[ 'name', '施設名' ],
			[ 'reservation_type', '時間貸／宿泊／両方' ],
			[ 'fee', '利用料金' ],
			[ 'start', '時間貸開始' ],
			[ 'end', '時間貸終了' ],
			[ 'stay_start', '宿泊開始' ],
			[ 'stay_end', '宿泊終了' ],
			[ 'stay_fee', '宿泊料金' ],
			[ 'additional_condition_1', '追加条件名1' ],
			[ 'additional_fee_1', '追加料金1' ],
			[ 'additional_condition_2', '追加条件名2' ],
			[ 'additional_fee_2', '追加料金2' ],
			[ 'additional_condition_3', '追加条件名3' ],
			[ 'additional_fee_3', '追加料金3' ],
			[ 'info', '施設情報・説明' ],
		];

		const persist = (message) => {
			dataInput.value = JSON.stringify( facilities );
			manager.dataset.facilities = dataInput.value;
			render();
			notice.textContent = message;
		};

		const selectFacility = (id) => {
			selectedId = id;
			rows.querySelectorAll( 'tr' ).forEach( (row) => {
				const selected = row.dataset.facilityId === selectedId;
				row.classList.toggle( 'is-selected', selected );
				row.querySelector( 'input[type="radio"]' ).checked = selected;
			} );
			editButton.disabled = ! selectedId;
			deleteButton.disabled = ! selectedId;
		};

		const render = () => {
			rows.replaceChildren();
			facilities.forEach( ( facility, index ) => {
				const row = document.createElement( 'tr' );
				row.dataset.facilityId = facility.id;
				const selectCell = document.createElement( 'th' );
				selectCell.scope = 'row';
				selectCell.className = 'wpplugin-facility-manager__select-column';
				const radio = document.createElement( 'input' );
				radio.type = 'radio';
				radio.name = 'wpplugin_facility_selected';
				radio.value = facility.id;
				radio.setAttribute( 'aria-label', `${ facility.name }を選択` );
				selectCell.append( radio );
				row.append( selectCell );

				fields.forEach( ( [ key ] ) => {
					const cell = document.createElement( 'td' );
					cell.textContent = String( facility[ key ] || '' );
					row.append( cell );
				} );
				const notifyCell = document.createElement( 'td' );
				notifyCell.textContent = facility.notify_management && facility.notify_management !== '0' ? 'あり' : 'なし';
				row.append( notifyCell );

				const sortCell = document.createElement( 'td' );
				sortCell.className = 'wpplugin-facility-manager__sort';
				[ [ 'up', '↑', '上へ' ], [ 'down', '↓', '下へ' ] ].forEach( ( [ action, label, description ] ) => {
					const button = document.createElement( 'button' );
					button.type = 'button';
					button.className = 'button button-small';
					button.dataset.action = action;
					button.textContent = label;
					button.setAttribute( 'aria-label', `${ facility.name }を${ description }` );
					button.disabled = action === 'up' ? index === 0 : index === facilities.length - 1;
					sortCell.append( button );
				} );
				row.append( sortCell );
				rows.append( row );
			} );
			emptyState.hidden = facilities.length > 0;
			selectFacility( facilities.some( ( facility ) => facility.id === selectedId ) ? selectedId : '' );
		};

		const openEditor = ( facility, isNew ) => {
			title.textContent = isNew ? '施設を追加' : '施設を編集';
			form.querySelectorAll( '[name]' ).forEach( ( input ) => {
				if ( input.type === 'checkbox' ) {
					input.checked = Boolean( facility[ input.name ] ) && facility[ input.name ] !== '0';
					return;
				}
				input.value = Object.prototype.hasOwnProperty.call( facility, input.name ) ? facility[ input.name ] : '';
			} );
			if ( isNew ) {
				form.querySelector( '[name="reservation_type"]' ).value = '時間貸';
			}
			dialog.showModal();
			form.querySelector( '[name="name"]' ).focus();
		};

		const saveFacility = () => {
			const nameInput = form.querySelector( '[name="name"]' );
			if ( ! nameInput.value.trim() ) {
				notice.textContent = '施設名を入力してください。';
				nameInput.focus();
				return;
			}
			const timePattern = /^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/;
			const invalidTime = [ 'start', 'end', 'stay_start', 'stay_end' ]
				.map( ( key ) => form.querySelector( `[name="${ key }"]` ) )
				.find( ( input ) => ! timePattern.test( input.value.trim() ) );
			if ( invalidTime ) {
				notice.textContent = '時刻は00:00〜23:59または24:00で入力してください。';
				invalidTime.focus();
				return;
			}
			const facility = Object.fromEntries( fields.map( ( [ key ] ) => [ key, form.querySelector( `[name="${ key }"]` ).value.trim() ] ) );
			facility.notify_management = form.querySelector( '[name="notify_management"]' ).checked ? '1' : '';
			const isNew = ! facility.id;
			if ( isNew ) {
				const baseId = `facility-${ Date.now().toString( 36 ) }`;
				facility.id = baseId;
				let suffix = 2;
				while ( facilities.some( ( item ) => item.id === facility.id ) ) {
					facility.id = `${ baseId }-${ suffix }`;
					suffix += 1;
				}
				facilities.push( facility );
			} else {
				facilities = facilities.map( ( item ) => item.id === facility.id ? facility : item );
			}
			selectedId = facility.id;
			dialog.close();
			persist( isNew ? '施設を追加しました。変更を反映するには設定を保存してください。' : '施設を更新しました。変更を反映するには設定を保存してください。' );
		};

		manager.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( 'button[data-action]' );
			const row = event.target.closest( 'tr[data-facility-id]' );
			if ( row && ! button ) {
				selectFacility( row.dataset.facilityId );
			}
			if ( ! button ) {
				return;
			}

			if ( button.dataset.action === 'add' ) {
				openEditor( { start: '09:00', end: '18:00', stay_start: '15:00', stay_end: '10:00' }, true );
			} else if ( button.dataset.action === 'edit' && selectedId ) {
				openEditor( facilities.find( ( facility ) => facility.id === selectedId ), false );
			} else if ( button.dataset.action === 'delete' && selectedId ) {
				const facility = facilities.find( ( item ) => item.id === selectedId );
				if ( facilities.length === 1 ) {
					notice.textContent = '施設を0件にはできません。削除する前に別の施設を追加してください。';
				} else if ( window.confirm( `「${ facility.name }」を削除しますか？` ) ) {
					facilities = facilities.filter( ( item ) => item.id !== selectedId );
					selectedId = '';
					persist( '施設を削除しました。変更を反映するには設定を保存してください。' );
				}
			} else if ( ( button.dataset.action === 'up' || button.dataset.action === 'down' ) && row ) {
				const index = facilities.findIndex( ( facility ) => facility.id === row.dataset.facilityId );
				const nextIndex = index + ( button.dataset.action === 'up' ? -1 : 1 );
				if ( nextIndex >= 0 && nextIndex < facilities.length ) {
					[ facilities[ index ], facilities[ nextIndex ] ] = [ facilities[ nextIndex ], facilities[ index ] ];
					persist( '施設の表示順を変更しました。変更を反映するには設定を保存してください。' );
				}
			} else if ( button.dataset.action === 'close' ) {
				dialog.close();
			} else if ( button.dataset.action === 'save-facility' ) {
				saveFacility();
			}
		} );

		rows.addEventListener( 'change', ( event ) => {
			if ( event.target.matches( 'input[type="radio"]' ) ) {
				selectFacility( event.target.value );
			}
		} );

		render();
	} );
}() );
