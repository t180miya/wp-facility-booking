( function () {
	'use strict';

	const calendars = document.querySelectorAll( '.wpplugin-calendar' );
	const weekdays = [ '日', '月', '火', '水', '木', '金', '土' ];

	calendars.forEach( ( calendar ) => {
		const yearSelect = calendar.querySelector( '.wpplugin-calendar__year' );
		const monthSelect = calendar.querySelector( '.wpplugin-calendar__month' );
		const facilitySelect = calendar.querySelector( '.wpplugin-calendar__facility' );
		const calendarToggle = calendar.querySelector( '.wpplugin-calendar__toggle' );
		const calendarPanel = calendar.querySelector( '.wpplugin-calendar__panel' );
		const datePicker = calendar.querySelector( '.wpplugin-calendar__date-picker' );
		const dateSelect = calendar.querySelector( '.wpplugin-calendar__date-select' );
		const selectedDateList = calendar.querySelector( '.wpplugin-calendar__selected-date-list' );
		const grid = calendar.querySelector( '.wpplugin-calendar__grid' );
		const list = calendar.querySelector( '.wpplugin-calendar__list' );
		const today = calendar.dataset.today;
		const facilities = JSON.parse( calendar.dataset.facilities || '[]' );
		let selectedFacility = facilities[ 0 ] || { id: 'default', start: '09:00', end: '18:00' };
		const defaultName = calendar.dataset.defaultName || '';
		const defaultEmail = calendar.dataset.defaultEmail || '';
		let reservations = JSON.parse( calendar.dataset.reservations || '[]' );
		const sanitizePreviewText = ( value ) => String( value || '' ).replace( /<[^>]*>/g, '' ).trim();
		const previewStorageKey = 'wpplugin-preview-reservations';
		if ( calendar.dataset.preview === 'true' ) {
			try {
				const storedReservations = localStorage.getItem( previewStorageKey );
				if ( storedReservations ) {
					reservations = JSON.parse( storedReservations );
				}
			} catch ( error ) {
				localStorage.removeItem( previewStorageKey );
			}
			reservations = reservations.map( ( reservation ) => {
				const isOwner = ! reservation.email || reservation.email.toLowerCase() === defaultEmail.toLowerCase();
				return isOwner ? {
					...reservation,
					title: sanitizePreviewText( reservation.title ),
					name: sanitizePreviewText( reservation.name ),
					details: sanitizePreviewText( reservation.details ),
				} : {
					id: reservation.id,
					facility_id: reservation.facility_id,
					date: reservation.date,
					start: reservation.start,
					end: reservation.end,
					title: '予約済み',
					reservation_type: reservation.reservation_type,
					group_id: reservation.group_id,
					status: reservation.status,
					can_edit: false,
				};
			} );
		}
		const persistPreviewReservations = () => {
			if ( calendar.dataset.preview === 'true' ) {
				localStorage.setItem( previewStorageKey, JSON.stringify( reservations ) );
			}
		};
		calendarToggle.addEventListener( 'click', () => {
			const expanded = calendarToggle.getAttribute( 'aria-expanded' ) === 'true';
			calendarToggle.setAttribute( 'aria-expanded', String( ! expanded ) );
			calendarToggle.textContent = expanded ? 'カレンダーを表示' : 'カレンダーを隠す';
			calendarPanel.hidden = expanded;
			datePicker.hidden = ! expanded;
			const reservation = calendar.parentElement.querySelector( '.wpplugin-reservation' );
			if ( reservation ) {
				reservation.hidden = true;
			}
			if ( ! expanded ) {
				selectedDateList.hidden = true;
			}
		} );
		const reservationForm = calendar.parentElement.querySelector( '.wpplugin-reservation__form' );
		const notice = calendar.querySelector( '.wpplugin-reservation__notice' );
		if ( notice && window.history && window.history.replaceState ) {
			const url = new URL( window.location.href );
			if ( url.searchParams.has( 'wpplugin_reservation_status' ) ) {
				url.searchParams.delete( 'wpplugin_reservation_status' );
				window.history.replaceState( null, '', url.toString() );
			}
		}
		if ( notice ) {
			const hideNotice = () => {
				notice.hidden = true;
			};
			calendar.addEventListener( 'change', hideNotice );
			calendar.addEventListener( 'click', hideNotice );
		}
		const reservationError = reservationForm.querySelector( '.wpplugin-reservation__error' );
		const roomNumberInput = reservationForm.querySelector( '[name="reservation_room_number"]' );
		roomNumberInput.addEventListener( 'input', () => {
			roomNumberInput.value = roomNumberInput.value
				.replace( /[０-９Ａ-Ｚａ-ｚ]/g, ( character ) => String.fromCharCode( character.charCodeAt( 0 ) - 0xFEE0 ) )
				.replace( /[^A-Za-z0-9]/g, '' );
			if ( ! reservationForm.querySelector( ':invalid' ) ) {
				reservationError.hidden = true;
				reservationError.textContent = '';
			}
		} );
		reservationForm.addEventListener( 'invalid', ( event ) => {
			reservationError.hidden = false;
			reservationError.textContent = event.target === roomNumberInput && event.target.validity.patternMismatch
				? '予約者部屋番号は半角英数字で入力してください。'
				: '必須項目を入力してください。';
		}, true );

		const reservationsFor = ( date ) => reservations
			.filter( ( reservation ) => reservation.date === date && String( reservation.facility_id || 'default' ) === String( selectedFacility.id ) )
			.sort( ( first, second ) => first.start.localeCompare( second.start ) );
		const timeToMinutes = ( time ) => {
			const [ hour, minute ] = time.split( ':' ).map( Number );
			return hour * 60 + minute;
		};
		const isFullyBooked = ( date ) => {
			const reservationsForDate = reservationsFor( date );
			const startMinutes = timeToMinutes( selectedFacility.start );
			const endMinutes = timeToMinutes( selectedFacility.end );
			for ( let slot = startMinutes; slot < endMinutes; slot += 30 ) {
				const slotEnd = slot + 30;
				if ( ! reservationsForDate.some( ( reservation ) => timeToMinutes( reservation.start ) <= slot && timeToMinutes( reservation.end ) >= slotEnd ) ) {
					return false;
				}
			}
			return reservationsForDate.length > 0;
		};
		const updateTimeOptions = () => {
			[ 'reservation_start', 'reservation_end' ].forEach( ( name ) => {
				const select = reservationForm.querySelector( `[name="${ name }"]` );
				const current = select.value;
				const [ startHour, startMinute ] = selectedFacility.start.split( ':' ).map( Number );
				const [ endHour, endMinute ] = selectedFacility.end.split( ':' ).map( Number );
				const startMinutes = startHour * 60 + startMinute;
				const endMinutes = endHour * 60 + endMinute - ( name === 'reservation_start' ? 30 : 0 );
				select.innerHTML = '<option value="">選択してください</option>';
				for ( let minutes = startMinutes; minutes <= endMinutes; minutes += 30 ) {
					const time = `${ String( Math.floor( minutes / 60 ) ).padStart( 2, '0' ) }:${ String( minutes % 60 ).padStart( 2, '0' ) }`;
					select.insertAdjacentHTML( 'beforeend', `<option value="${ time }">${ time }</option>` );
				}
				select.value = current;
			} );
		};
		const stripYen = ( value ) => String( value || '' ).replace( /\s*円\s*$/, '' );
		const updateFacilityInfo = () => {
			calendar.parentElement.querySelector( '.wpplugin-facility-info__name' ).textContent = String( selectedFacility.name || '' );
			const isBoth = selectedFacility.reservation_type === '両方';
			const isStayOnly = selectedFacility.reservation_type === '宿泊';
			const hourlyFeeRow = calendar.parentElement.querySelector( '.wpplugin-facility-info__fee-row' );
			const hoursRow = calendar.parentElement.querySelector( '.wpplugin-facility-info__hours-row' );
			hourlyFeeRow.hidden = isStayOnly;
			hoursRow.hidden = isStayOnly;
			calendar.parentElement.querySelector( '.wpplugin-facility-info__fee-label' ).textContent = isBoth ? '利用料金（時間貸）' : '利用料金';
			calendar.parentElement.querySelector( '.wpplugin-facility-info__fee' ).textContent = String( selectedFacility.fee || '' );
			const stayFeeRow = calendar.parentElement.querySelector( '.wpplugin-facility-info__stay-fee-row' );
			stayFeeRow.hidden = ! ( isBoth || isStayOnly );
			if ( isBoth || isStayOnly ) {
				calendar.parentElement.querySelector( '.wpplugin-facility-info__stay-fee' ).textContent = `${ stripYen( selectedFacility.stay_fee ) } 円／泊`;
			}
			const stayHoursRow = calendar.parentElement.querySelector( '.wpplugin-facility-info__stay-hours-row' );
			stayHoursRow.hidden = ! ( isBoth || isStayOnly );
			if ( isBoth || isStayOnly ) {
				calendar.parentElement.querySelector( '.wpplugin-facility-info__stay-hours' ).textContent = `${ String( selectedFacility.stay_start || '' ) }〜${ String( selectedFacility.stay_end || '' ) } ※ 翌日`;
			}
			calendar.parentElement.querySelector( '.wpplugin-facility-info__hours' ).textContent = `${ String( selectedFacility.start || '' ) }〜${ String( selectedFacility.end || '' ) }`;
			calendar.parentElement.querySelector( '.wpplugin-facility-info__description' ).textContent = String( selectedFacility.info || '' );
		};
		const resetReservationForm = () => {
			reservationForm.reset();
			reservationForm.querySelector( '.wpplugin-reservation__id' ).value = '';
			reservationForm.querySelector( '.wpplugin-reservation__facility-id' ).value = selectedFacility.id;
			reservationForm.querySelector( '.wpplugin-reservation__error' ).hidden = true;
			reservationForm.closest( '.wpplugin-reservation' ).hidden = true;
		};
		const weekdayNames = [ '日', '月', '火', '水', '木', '金', '土' ];
		const weekdayIndexFor = ( date ) => new Date( `${ date }T00:00:00` ).getDay();
		const weekdayFor = ( date ) => weekdayNames[ weekdayIndexFor( date ) ];
		const selectedDateLabel = ( date ) => {
			const [ year, month, day ] = date.split( '-' ).map( Number );
			return `${ year }年${ month }月${ day }日（${ weekdayFor( date ) }）`;
		};
		const dateAndWeekdayLabel = ( date ) => `${ Number( date.slice( -2 ) ) }日（${ weekdayFor( date ) }）`;
		const escapeHtml = ( value ) => String( value ).replace( /[&<>'"]/g, ( character ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[ character ] ) );
		const truncateTitle = ( title ) => Array.from( title ).slice( 0, 10 ).join( '' ) + ( Array.from( title ).length > 10 ? '…' : '' );
		const updateReservationFields = () => {
			const typeRow = reservationForm.querySelector( '.wpplugin-reservation__type-row' );
			const typeSelect = reservationForm.querySelector( '.wpplugin-reservation__type' );
			const hourlyTimes = reservationForm.querySelector( '.wpplugin-reservation__hourly-times' );
			const configuredType = String( selectedFacility.reservation_type || '時間貸' );
			const supportsBoth = configuredType === '両方';
			const isStayOnly = configuredType === '宿泊';
			if ( supportsBoth ) {
				typeRow.hidden = false;
			} else {
				typeRow.hidden = true;
				typeSelect.value = isStayOnly ? '宿泊' : '時間貸';
			}
			const isStay = typeSelect.value === '宿泊';
			const titleInput = reservationForm.querySelector( '[name="reservation_title"]' );
			if ( isStay ) {
				titleInput.value = '宿泊';
				const detailsInput = reservationForm.querySelector( '[name="reservation_details"]' );
				if ( ! detailsInput.value ) {
					detailsInput.value = '利用人数：';
				}
			} else {
				titleInput.value = '';
			}
			titleInput.readOnly = isStay;
			hourlyTimes.hidden = isStay;
			hourlyTimes.querySelectorAll( 'select' ).forEach( ( select ) => {
				select.disabled = isStay;
				select.required = ! isStay;
			} );
			const stayFee = reservationForm.querySelector( '.wpplugin-reservation__stay-fee' );
			stayFee.hidden = ! isStay;
			stayFee.querySelector( '.wpplugin-reservation__stay-fee-value' ).textContent = stripYen( selectedFacility.stay_fee );
			const conditions = reservationForm.querySelector( '.wpplugin-reservation__additional-conditions' );
			conditions.innerHTML = [ 1, 2, 3 ].map( ( index ) => {
				const condition = String( selectedFacility[ `additional_condition_${ index }` ] || '' );
				const fee = String( selectedFacility[ `additional_fee_${ index }` ] || '' );
				const feeLabel = fee && ! fee.includes( '円' ) ? `${ fee } 円` : fee;
				return condition ? `<label>${ escapeHtml( condition ) }${ feeLabel ? `（${ escapeHtml( feeLabel ) }）` : '' }<input type="number" name="additional_quantity_${ index }" min="0" step="1" value="0"></label>` : '';
			} ).join( '' );
		};
		const statusPrefix = ( reservation ) => reservation.status === 'tentative' ? '【仮予約】' : '';
		const reservationMarkup = ( date ) => reservationsFor( date ).slice( 0, 3 ).map( ( reservation ) => {
			const text = `${ statusPrefix( reservation ) }${ escapeHtml( reservation.start ) }-${ escapeHtml( reservation.end ) } ${ escapeHtml( truncateTitle( reservation.title ) ) }`;
			return reservation.can_edit ? `<button type="button" class="wpplugin-calendar__reservation wpplugin-calendar__reservation-link" data-reservation-id="${ reservation.id }">${ text }</button>` : `<span class="wpplugin-calendar__reservation is-others">${ text }</span>`;
		} ).join( '' );
		const renderDateOptions = () => {
			const year = Number( yearSelect.value );
			const month = Number( monthSelect.value );
			const daysCount = new Date( year, month, 0 ).getDate();
			const selectedDate = dateSelect.value;
			const dates = Array.from( { length: daysCount }, ( value, index ) => {
				const day = index + 1;
				const date = [ year, String( month ).padStart( 2, '0' ), String( day ).padStart( 2, '0' ) ].join( '-' );
				return `<option value="${ date }">${ dateAndWeekdayLabel( date ) }</option>`;
			} );
			dateSelect.innerHTML = '<option value="">日付を指定</option>' + dates.join( '' );
			dateSelect.value = dates.some( ( option ) => option.includes( `value="${ selectedDate }"` ) ) ? selectedDate : '';
		};
		const renderSelectedDateList = ( date ) => {
			const entries = reservationsFor( date );
			const details = entries.length ? entries.map( ( reservation ) => {
				const text = `${ statusPrefix( reservation ) }${ escapeHtml( reservation.start ) }-${ escapeHtml( reservation.end ) } ${ escapeHtml( reservation.title ) }`;
				return reservation.can_edit ? `<button type="button" class="wpplugin-calendar__reservation-link" data-reservation-id="${ reservation.id }">${ text }</button>` : `<span class="is-others">${ text }</span>`;
			} ).join( '' ) : '<span class="wpplugin-calendar__no-reservations">予約なし</span>';
			selectedDateList.innerHTML = `<div class="wpplugin-calendar__selected-date-item${ isFullyBooked( date ) ? ' is-full-day' : '' }" data-date="${ date }"><span class="wpplugin-calendar__new-reservation-action"><button type="button" class="wpplugin-calendar__new-reservation" data-date="${ date }">新規予約</button> または更新</span><span class="wpplugin-calendar__list-details">${ details }</span></div>`;
			selectedDateList.hidden = false;
		};

		const render = () => {
			const year = Number( yearSelect.value );
			const month = Number( monthSelect.value );
			const firstDay = new Date( year, month - 1, 1 );
			const startWeek = firstDay.getDay();
			const daysCount = new Date( year, month, 0 ).getDate();
			const cells = [];

			for ( let day = 0; day < startWeek; day += 1 ) {
				cells.push( '<td class="is-empty" aria-hidden="true"></td>' );
			}

			for ( let day = 1; day <= daysCount; day += 1 ) {
				const date = [ year, String( month ).padStart( 2, '0' ), String( day ).padStart( 2, '0' ) ].join( '-' );
				const todayClass = date === today ? 'is-today' : '';
				const emptyDayClass = reservationsFor( date ).length === 0 ? ' is-empty-day' : '';
				const fullDayClass = isFullyBooked( date ) ? ' is-full-day' : '';
				const cellClasses = `${ todayClass }${ emptyDayClass }${ fullDayClass }`.trim();
				const currentAttribute = date === today ? ' aria-current="date"' : '';
				cells.push( `<td class="${ cellClasses }"${ currentAttribute } data-date="${ date }"><button type="button" class="wpplugin-calendar__day" data-date="${ date }"><span class="wpplugin-calendar__day-number">${ day }</span></button>${ reservationMarkup( date ) }</td>` );
			}

			while ( cells.length % 7 !== 0 ) {
				cells.push( '<td class="is-empty" aria-hidden="true"></td>' );
			}

			const rows = [];
			for ( let index = 0; index < cells.length; index += 7 ) {
				rows.push( `<tr>${ cells.slice( index, index + 7 ).join( '' ) }</tr>` );
			}

			grid.innerHTML = `
				<table class="wpplugin-calendar__table">
					<thead><tr>${ weekdays.map( ( weekday ) => `<th scope="col">${ weekday }</th>` ).join( '' ) }</tr></thead>
					<tbody>${ rows.join( '' ) }</tbody>
				</table>`;

			const monthPrefix = `${ year }-${ String( month ).padStart( 2, '0' ) }-`;
			const days = Array.from( { length: daysCount }, ( value, index ) => String( index + 1 ).padStart( 2, '0' ) );
			list.innerHTML = days.map( ( day ) => {
				const date = `${ monthPrefix }${ day }`;
				const entries = reservationsFor( date );
				const details = entries.length ? entries.map( ( reservation ) => {
					const text = `${ statusPrefix( reservation ) }${ escapeHtml( reservation.start ) }-${ escapeHtml( reservation.end ) } ${ escapeHtml( reservation.title ) }`;
					return reservation.can_edit ? `<button type="button" class="wpplugin-calendar__reservation-link" data-reservation-id="${ reservation.id }">${ text }</button>` : `<span class="is-others">${ text }</span>`;
				} ).join( '<br>' ) : '<span class="wpplugin-calendar__no-reservations">予約なし</span>';
				const weekendClass = weekdayIndexFor( date ) === 0 ? ' is-sunday' : ( weekdayIndexFor( date ) === 6 ? ' is-saturday' : '' );
				const fullDayClass = isFullyBooked( date ) ? ' is-full-day' : '';
				return `<div class="wpplugin-calendar__list-item${ weekendClass }${ fullDayClass }" data-date="${ date }"><button type="button" class="wpplugin-calendar__day" data-date="${ date }"><strong>${ Number( day ) }日 (${ weekdayFor( date ) })</strong></button><span class="wpplugin-calendar__list-details">${ details }</span></div>`;
			} ).join( '' );
			renderDateOptions();
			if ( dateSelect.value ) {
				renderSelectedDateList( dateSelect.value );
			} else {
				selectedDateList.hidden = true;
			}
		};

		yearSelect.addEventListener( 'change', () => {
			reservationForm.closest( '.wpplugin-reservation' ).hidden = true;
			render();
		} );
		monthSelect.addEventListener( 'change', () => {
			reservationForm.closest( '.wpplugin-reservation' ).hidden = true;
			render();
		} );
		dateSelect.addEventListener( 'change', () => {
			const reservation = calendar.parentElement.querySelector( '.wpplugin-reservation' );
			if ( reservation ) {
				reservation.hidden = true;
			}
			if ( dateSelect.value ) {
				renderSelectedDateList( dateSelect.value );
			} else {
				selectedDateList.hidden = true;
			}
		} );
		reservationForm.querySelector( '.wpplugin-reservation__type' ).addEventListener( 'change', updateReservationFields );
		facilitySelect.addEventListener( 'change', () => {
			selectedFacility = facilities.find( ( facility ) => String( facility.id ) === facilitySelect.value ) || facilities[ 0 ];
			resetReservationForm();
			updateFacilityInfo();
			updateTimeOptions();
			updateReservationFields();
			selectedDateList.hidden = true;
			render();
		} );
		reservationForm.addEventListener( 'submit', ( event ) => {
			const date = reservationForm.querySelector( '.wpplugin-reservation__date' ).value;
			const isStay = reservationForm.querySelector( '.wpplugin-reservation__type' ).value === '宿泊';
			const start = isStay ? String( selectedFacility.stay_start || '' ) : reservationForm.querySelector( '[name="reservation_start"]' ).value;
			const end = isStay ? '24:00' : reservationForm.querySelector( '[name="reservation_end"]' ).value;
			const reservationId = reservationForm.querySelector( '.wpplugin-reservation__id' ).value;
			const error = reservationForm.querySelector( '.wpplugin-reservation__error' );
			const overlaps = reservations.some( ( existing ) => existing.id !== Number( reservationId ) && String( existing.facility_id || 'default' ) === String( selectedFacility.id ) && existing.date === date && start < existing.end && end > existing.start );
			if ( ! start || ! end || ( ! isStay && end <= start ) ) {
				event.preventDefault();
				error.hidden = false;
				error.textContent = '終了時刻は開始時刻より後を指定してください。';
				error.scrollIntoView( { behavior: 'auto', block: 'nearest' } );
				return;
			}

			if ( overlaps ) {
				event.preventDefault();
				error.hidden = false;
				error.textContent = '指定した時間帯は、すでに予約されている時間帯と重なっています。別の時間を指定してください。';
				error.scrollIntoView( { behavior: 'auto', block: 'nearest' } );
			} else if ( reservationForm.dataset.preview === 'true' ) {
				event.preventDefault();
				const reservationIndex = reservations.findIndex( ( reservation ) => String( reservation.id ) === reservationId );
				const isUpdate = reservationIndex >= 0 && reservations[ reservationIndex ].can_edit;
				const groupId = isUpdate ? String( reservations[ reservationIndex ].group_id || reservations[ reservationIndex ].id ) : String( Date.now() );
				const reservationKey = isUpdate ? reservations[ reservationIndex ].id : Number( groupId );
				const saved = {
					id: reservationKey,
					group_id: groupId,
					group_primary_id: reservationKey,
					group_start_date: date,
					facility_id: selectedFacility.id,
					date,
					start,
					end,
					reservation_type: isStay ? '宿泊' : '時間貸',
					additional_quantities: [ 1, 2, 3 ].map( ( index ) => reservationForm.querySelector( `[name="additional_quantity_${ index }"]` )?.value || '0' ),
					title: sanitizePreviewText( reservationForm.querySelector( '[name="reservation_title"]' ).value ),
					name: sanitizePreviewText( reservationForm.querySelector( '[name="reservation_name"]' ).value ),
					email: defaultEmail,
					room_number: roomNumberInput.value,
					details: sanitizePreviewText( reservationForm.querySelector( '[name="reservation_details"]' ).value ),
					cost_by_association: Boolean( reservationForm.querySelector( '[name="cost_by_association"]' )?.checked ),
					status: reservationForm.querySelector( '[name="cost_by_association"]' )?.checked ? 'tentative' : 'confirmed',
					can_edit: true,
				};
				if ( isUpdate ) {
					reservations = reservations.filter( ( reservation ) => String( reservation.group_id || reservation.id ) !== groupId );
				}
				reservations.push( saved );
				if ( isStay ) {
					const nextDate = new Date( `${ date }T00:00:00` );
					nextDate.setDate( nextDate.getDate() + 1 );
					const nextDateValue = `${ nextDate.getFullYear() }-${ String( nextDate.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( nextDate.getDate() ).padStart( 2, '0' ) }`;
					reservations.push( {
						...saved,
						id: Number( groupId ) + 1,
						date: nextDateValue,
						start: '00:00',
						end: String( selectedFacility.stay_end || '' ),
					} );
				}
				persistPreviewReservations();
				reservationForm.reset();
				reservationForm.querySelector( '.wpplugin-reservation__id' ).value = '';
				reservationForm.closest( '.wpplugin-reservation' ).hidden = true;
				if ( notice ) {
					notice.textContent = isUpdate ? '予約を更新しました。' : '予約が完了しました。';
					notice.hidden = false;
				}
				render();
			} else {
				error.hidden = true;
				error.textContent = '';
			}
		} );
		const cancelButton = reservationForm.querySelector( '.wpplugin-reservation__cancel' );
		if ( cancelButton && cancelButton.type === 'button' ) {
			cancelButton.addEventListener( 'click', () => {
				const reservationId = reservationForm.querySelector( '.wpplugin-reservation__id' ).value;
				const reservationIndex = reservations.findIndex( ( item ) => String( item.id ) === reservationId );
				if ( reservationIndex >= 0 ) {
					const groupId = String( reservations[ reservationIndex ].group_id || reservations[ reservationIndex ].id );
					reservations = reservations.filter( ( item ) => String( item.group_id || item.id ) !== groupId );
				}
				persistPreviewReservations();

				reservationForm.reset();
				reservationForm.querySelector( '.wpplugin-reservation__id' ).value = '';
				reservationForm.querySelector( '.wpplugin-reservation__error' ).hidden = true;
				reservationForm.closest( '.wpplugin-reservation' ).hidden = true;
				if ( notice ) {
					notice.textContent = '予約キャンセルが完了しました。';
					notice.hidden = false;
				}
				render();
			} );
		}
		calendar.addEventListener( 'click', ( event ) => {
			const reservationLink = event.target.closest( '.wpplugin-calendar__reservation-link' );
			const reservation = calendar.parentElement.querySelector( '.wpplugin-reservation' );
			if ( reservationLink && reservation ) {
				const selected = reservations.find( ( item ) => String( item.id ) === reservationLink.dataset.reservationId );
				if ( selected && selected.can_edit ) {
					reservation.hidden = false;
					reservation.querySelector( '.wpplugin-reservation__id' ).value = selected.group_primary_id || selected.id;
					reservation.querySelector( '.wpplugin-reservation__facility-id' ).value = selected.facility_id || selectedFacility.id;
					reservation.querySelector( '.wpplugin-reservation__date' ).value = selected.group_start_date || selected.date;
					reservation.querySelector( '.wpplugin-reservation__type' ).value = selected.reservation_type || '時間貸';
					updateReservationFields();
						const quantities = Array.isArray( selected.additional_quantities )
							? selected.additional_quantities.map( ( quantity, index ) => [ index + 1, quantity ] )
							: Object.entries( selected.additional_quantities || {} );
					quantities.forEach( ( [ index, quantity ] ) => {
						const input = reservation.querySelector( `[name="additional_quantity_${ index }"]` );
						if ( input ) {
							input.value = quantity;
						}
					} );
					reservation.querySelector( '.wpplugin-reservation__selected-date' ).textContent = selectedDateLabel( selected.group_start_date || selected.date );
					reservation.querySelector( '[name="reservation_start"]' ).value = selected.start;
					reservation.querySelector( '[name="reservation_end"]' ).value = selected.end;
					reservation.querySelector( '[name="reservation_title"]' ).value = selected.reservation_type === '宿泊' ? '宿泊' : selected.title;
					reservation.querySelector( '[name="reservation_name"]' ).value = selected.name;
					reservation.querySelector( '[name="reservation_email"]' ).value = selected.email || defaultEmail;
					roomNumberInput.value = selected.room_number || '';
					reservation.querySelector( '[name="reservation_details"]' ).value = selected.details;
					const selectedCostCheckbox = reservation.querySelector( '[name="cost_by_association"]' );
					if ( selectedCostCheckbox ) {
						selectedCostCheckbox.checked = Boolean( selected.cost_by_association );
					}
					reservation.querySelector( 'button[type="submit"]' ).textContent = '予約を更新';
					reservation.querySelector( '.wpplugin-reservation__cancel' ).hidden = false;
					reservation.scrollIntoView( { behavior: 'auto', block: 'nearest' } );
				}
				return;
			}

			let dayButton = event.target.closest( '.wpplugin-calendar__day' );
			const newReservationButton = event.target.closest( '.wpplugin-calendar__new-reservation' );
			if ( newReservationButton ) {
				dayButton = newReservationButton;
			}
			const listItem = event.target.closest( '.wpplugin-calendar__list-item' );
			if ( ! dayButton && event.target.closest( '.wpplugin-calendar__no-reservations' ) && listItem ) {
				dayButton = listItem.querySelector( '.wpplugin-calendar__day' );
			}
			if ( ! dayButton && listItem && reservationsFor( listItem.dataset.date ).length === 0 ) {
				dayButton = listItem.querySelector( '.wpplugin-calendar__day' );
			}
			if ( ! dayButton && event.target.closest( 'td.is-empty-day' ) ) {
				dayButton = event.target.closest( 'td.is-empty-day' ).querySelector( '.wpplugin-calendar__day' );
			}
			if ( ! dayButton && event.target.closest( '.wpplugin-calendar__selected-date-item' ) && reservationsFor( event.target.closest( '.wpplugin-calendar__selected-date-item' ).dataset.date ).length === 0 ) {
				dayButton = { dataset: { date: event.target.closest( '.wpplugin-calendar__selected-date-item' ).dataset.date } };
			}
			if ( ! dayButton ) {
				return;
			}

			if ( ! reservation ) {
				return;
			}

			const date = dayButton.dataset.date;
			reservation.hidden = false;
			reservation.querySelector( '.wpplugin-reservation__id' ).value = '';
			reservation.querySelector( '.wpplugin-reservation__facility-id' ).value = selectedFacility.id;
			reservation.querySelector( '.wpplugin-reservation__date' ).value = date;
			updateReservationFields();
			reservation.querySelector( '.wpplugin-reservation__selected-date' ).textContent = selectedDateLabel( date );
			reservation.querySelector( '[name="reservation_start"]' ).value = '';
			reservation.querySelector( '[name="reservation_end"]' ).value = '';
			reservation.querySelector( '[name="reservation_title"]' ).value = reservation.querySelector( '.wpplugin-reservation__type' ).value === '宿泊' ? '宿泊' : '';
			reservation.querySelector( '[name="reservation_name"]' ).value = defaultName;
			reservation.querySelector( '[name="reservation_email"]' ).value = defaultEmail;
			roomNumberInput.value = '';
			reservation.querySelector( '[name="reservation_details"]' ).value = reservation.querySelector( '.wpplugin-reservation__type' ).value === '宿泊' ? '利用人数：' : '';
			reservation.querySelector( 'button[type="submit"]' ).textContent = '予約を保存';
			const costCheckbox = reservation.querySelector( '[name="cost_by_association"]' );
			if ( costCheckbox ) {
				costCheckbox.checked = false;
			}
			reservation.querySelector( '.wpplugin-reservation__cancel' ).hidden = true;
			reservation.scrollIntoView( { behavior: 'auto', block: 'nearest' } );
		} );
		updateFacilityInfo();
		updateTimeOptions();
		updateReservationFields();
		render();
	} );
}() );