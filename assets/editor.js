( function ( wp ) {
	var registerBlockType = wp.blocks.registerBlockType;
	var __ = wp.i18n.__;
	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var useInnerBlocksProps = wp.blockEditor.useInnerBlocksProps;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var useBlockPropsSave = useBlockProps.save;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var ToggleControl = wp.components.ToggleControl;
	var SelectControl = wp.components.SelectControl;
	var __experimentalNumberControl = wp.components.__experimentalNumberControl;
	var Notice = wp.components.Notice;
	var FormTokenField = wp.components.FormTokenField;
	var createBlock = wp.blocks.createBlock;
	var PanelColorSettings = wp.blockEditor.PanelColorSettings;
	var PanelColorGradientSettings =
		wp.blockEditor.PanelColorGradientSettings ||
		wp.blockEditor.__experimentalPanelColorGradientSettings ||
		null;
	/** Erkennt gespeicherte CSS-Verläufe (ein Wert pro Attribut). */
	var BDFRMS_GRADIENT_VALUE_RE =
		/^(?:linear|radial|conic|repeating-linear|repeating-radial|repeating-conic)-gradient\s*\(/i;
	function bdfrmsAttrLooksLikeCssGradient( raw ) {
		if ( raw == null || String( raw ).trim() === '' ) {
			return false;
		}
		return BDFRMS_GRADIENT_VALUE_RE.test( String( raw ).trim() );
	}
	/**
	 * @param {Array<{label:string,value?:string,onChange:function(string):void}>} rows
	 * @return {Array<Record<string, unknown>>}
	 */
	function mapColorSettingsToGradientSettings( rows ) {
		return rows.map( function ( row ) {
			var raw = row.value != null ? String( row.value ).trim() : '';
			var isGrad = BDFRMS_GRADIENT_VALUE_RE.test( raw );
			// ColorGradientControl ruft nach Farbwahl onColorChange( neu ) und direkt onGradientChange() ohne Arg (nur Verlauf-Slot leeren) — und umgekehrt. ToolsPanel „Zurücksetzen“ ruft dagegen nur einen der beiden ohne Arg auf → Wert leeren.
			var suppressNextNoArgGradient = false;
			var suppressNextNoArgColor = false;
			return {
				label: row.label,
				colorValue: isGrad ? undefined : raw || undefined,
				gradientValue: isGrad ? raw : undefined,
				onColorChange: function ( v ) {
					if ( arguments.length === 0 ) {
						if ( suppressNextNoArgColor ) {
							suppressNextNoArgColor = false;
							return;
						}
						row.onChange( '' );
						return;
					}
					suppressNextNoArgGradient = true;
					row.onChange( v == null ? '' : String( v ) );
					queueMicrotask( function () {
						suppressNextNoArgGradient = false;
					} );
				},
				onGradientChange: function ( v ) {
					if ( arguments.length === 0 ) {
						if ( suppressNextNoArgGradient ) {
							suppressNextNoArgGradient = false;
							return;
						}
						row.onChange( '' );
						return;
					}
					suppressNextNoArgColor = true;
					row.onChange( v == null ? '' : String( v ) );
					queueMicrotask( function () {
						suppressNextNoArgColor = false;
					} );
				},
				enableAlpha: true,
			};
		} );
	}
	/**
	 * Farbe + optional Verlauf (WP-Komponente) bzw. nur Farbe mit Alphakanal als Fallback.
	 *
	 * @param {string} title
	 * @param {Array<{label:string,value?:string,onChange:function(string):void}>} rows
	 * @param {{wrapInPanelBody?:boolean,panelBodyInitialOpen?:boolean}|undefined} options
	 *        wrapInPanelBody: z. B. Feld-Inspector — PanelBody mit initialOpen zuverlässig zugeklappt
	 *        (PanelColorGradientSettings basiert auf ToolsPanel und ignoriert initialOpen oft).
	 */
	function renderGfbColorPanel( title, rows, options ) {
		options = options || {};
		var usePanelBody = !! options.wrapInPanelBody;
		var panelBodyOpen =
			options.panelBodyInitialOpen !== undefined ? !! options.panelBodyInitialOpen : false;

		function innerColorPanel() {
			if ( PanelColorGradientSettings ) {
				// Theme kann color.customGradient / Paletten deaktivieren — dann wäre canChooseAGradient false und kein Verlauf-Tab. Für Formularfarben immer eigene Farben + Verläufe erlauben.
				var gradProps = {
					disableCustomColors: false,
					disableCustomGradients: false,
					settings: mapColorSettingsToGradientSettings( rows ),
				};
				if ( usePanelBody ) {
					gradProps.title = '';
					gradProps.initialOpen = true;
				} else {
					gradProps.title = title;
					gradProps.initialOpen = false;
				}
				return el( PanelColorGradientSettings, gradProps );
			}
			var colorProps = {
				colorSettings: rows.map( function ( r ) {
					return Object.assign( {}, r, { enableAlpha: true } );
				} ),
			};
			if ( usePanelBody ) {
				colorProps.title = '';
				colorProps.initialOpen = true;
			} else {
				colorProps.title = title;
				colorProps.initialOpen = false;
			}
			return el( PanelColorSettings, colorProps );
		}

		if ( usePanelBody ) {
			return el(
				PanelBody,
				{
					title: title,
					initialOpen: panelBodyOpen,
				},
				innerColorPanel()
			);
		}
		return innerColorPanel();
	}
	/**
	 * Hält formId pro Block-Instanz eindeutig: Duplizieren kopiert formId/blockInstanceId,
	 * daher Abgleich mit der stabilen Editor-clientId (neue formId bei neuer Instanz).
	 */
	function syncFormInstance( attributes, setAttributes, clientId ) {
		useEffect(
			function () {
				if ( attributes.blockInstanceId === clientId ) {
					return;
				}
				/* Alte Inhalte ohne blockInstanceId: formId behalten, nur Instanz binden. */
				if ( ! attributes.blockInstanceId && attributes.formId ) {
					setAttributes( { blockInstanceId: clientId } );
					return;
				}
				/* Kopie / Einfügen / neu: neue formId; Empfänger-Default nur hier (einmalig). */
				var generated = 'bdfrms_' + clientId.replace( /-/g, '' ).slice( 0, 12 );
				var patch = {
					blockInstanceId: clientId,
					formId: generated,
				};
				if ( bdfrmsNormalizeEmailRecipientsArray( attributes.emailRecipients ).length === 0 ) {
					var admin = bdfrmsGetDefaultAdminEmail();
					if ( admin ) {
						patch.emailRecipients = [ admin ];
					}
				}
				setAttributes( patch );
			},
			[ attributes.blockInstanceId, attributes.formId, attributes.emailRecipients, clientId, setAttributes ]
		);
	}

	/**
	 * Startwert für range: optional defaultValue, sonst Mitte (auf Schritt gerundet).
	 *
	 * @param {string} minStr
	 * @param {string} maxStr
	 * @param {string} stepStr
	 * @param {string|undefined} defaultStr
	 * @return {string}
	 */
	function computeRangeInitial( minStr, maxStr, stepStr, defaultStr ) {
		var min = parseFloat( minStr );
		var max = parseFloat( maxStr );
		var step = parseFloat( stepStr );
		if ( Number.isNaN( min ) ) {
			min = 0;
		}
		if ( Number.isNaN( max ) ) {
			max = 100;
		}
		if ( Number.isNaN( step ) || step <= 0 ) {
			step = 1;
		}
		if ( max < min ) {
			var t = min;
			min = max;
			max = t;
		}
		if ( defaultStr !== undefined && defaultStr !== null && String( defaultStr ).trim() !== '' ) {
			var d = parseFloat( defaultStr );
			if ( ! Number.isNaN( d ) ) {
				d = Math.min( max, Math.max( min, d ) );
				return snapRangeToStep( d, min, max, step );
			}
		}
		return snapRangeToStep( ( min + max ) / 2, min, max, step );
	}

	/**
	 * @param {number} value
	 * @param {number} min
	 * @param {number} max
	 * @param {number} step
	 * @return {string}
	 */
	function snapRangeToStep( value, min, max, step ) {
		var steps = Math.round( ( value - min ) / step );
		var v = min + steps * step;
		if ( v > max ) {
			v = max;
		}
		if ( v < min ) {
			v = min;
		}
		var stepStr = String( step );
		var dot = stepStr.indexOf( '.' );
		var decimals = dot >= 0 ? stepStr.length - dot - 1 : 0;
		if ( decimals > 0 ) {
			return v.toFixed( decimals );
		}
		return String( Math.round( v ) );
	}

	/** Frühere block.json-Defaults (alle Felder eines Typs hatten denselben Namen → nur ein Wert in PHP). */
	var BDFRMS_LEGACY_NAMES_TEXT = [ 'textfeld' ];
	var BDFRMS_LEGACY_NAMES_EMAIL = [ 'email' ];
	var BDFRMS_LEGACY_NAMES_TEXTAREA = [ 'nachricht' ];
	var BDFRMS_LEGACY_NAMES_SELECT = [ 'auswahl' ];
	var BDFRMS_LEGACY_NAMES_CHECKBOX = [ 'zustimmung' ];
	var BDFRMS_LEGACY_NAMES_NUMBER = [ 'zahl' ];
	var BDFRMS_LEGACY_NAMES_TEL = [ 'telefon' ];
	var BDFRMS_LEGACY_NAMES_URL = [ 'website' ];
	var BDFRMS_LEGACY_NAMES_DATE = [ 'datum' ];
	var BDFRMS_LEGACY_NAMES_TIME = [ 'uhrzeit' ];
	var BDFRMS_LEGACY_NAMES_DATETIME = [ 'termin' ];
	var BDFRMS_LEGACY_NAMES_RADIO = [ 'auswahl_radio' ];
	var BDFRMS_LEGACY_NAMES_HIDDEN = [ 'referenz' ];
	var BDFRMS_LEGACY_NAMES_RANGE = [ 'wert' ];
	var BDFRMS_LEGACY_NAMES_FILE = [ 'datei' ];

	/** Reihenfolge im Formular-Inserter: Felder zuerst (Core nutzt die Reihenfolge von allowedBlocks). */
	/**
	 * Felder unter bdfrms/form für die Rückmeldungsfelder (ohne Rückmeldung, ohne Absenden).
	 *
	 * @param {Array} blocks
	 * @return {Array<{name:string,label:string}>}
	 */
	function bdfrmsCollectFormFieldTokenRows( blocks ) {
		var rows = [];
		if ( ! blocks || ! blocks.length ) {
			return rows;
		}
		blocks.forEach( function ( b ) {
			if ( ! b || ! b.name ) {
				return;
			}
			if ( b.name === 'bdfrms/form-success' ) {
				return;
			}
			if ( b.name.indexOf( 'bdfrms/field-' ) === 0 && b.name !== 'bdfrms/field-submit' ) {
				var attrs = b.attributes || {};
				var nm = attrs.name != null ? String( attrs.name ).trim() : '';
				if ( nm ) {
					rows.push( {
						name: nm,
						label: attrs.label != null ? String( attrs.label ) : '',
					} );
				}
				return;
			}
			if ( b.innerBlocks && b.innerBlocks.length ) {
				rows = rows.concat( bdfrmsCollectFormFieldTokenRows( b.innerBlocks ) );
			}
		} );
		return rows;
	}

	/**
	 * E-Mail-Felder unter bdfrms/form (für Absender-Auswahl).
	 *
	 * @param {Array} blocks
	 * @return {Array<{name:string,label:string}>}
	 */
	function bdfrmsCollectFormEmailFieldRows( blocks ) {
		var rows = [];
		if ( ! blocks || ! blocks.length ) {
			return rows;
		}
		blocks.forEach( function ( b ) {
			if ( ! b || ! b.name ) {
				return;
			}
			if ( b.name === 'bdfrms/field-email' ) {
				var attrs = b.attributes || {};
				var nm = attrs.name != null ? String( attrs.name ).trim() : '';
				if ( nm ) {
					rows.push( {
						name: nm,
						label: attrs.label != null ? String( attrs.label ) : '',
					} );
				}
				return;
			}
			if ( b.innerBlocks && b.innerBlocks.length ) {
				rows = rows.concat( bdfrmsCollectFormEmailFieldRows( b.innerBlocks ) );
			}
		} );
		return rows;
	}

	/**
	 * @param {string} value
	 * @return {boolean}
	 */
	function bdfrmsIsValidEmailToken( value ) {
		var s = value == null ? '' : String( value ).trim();
		if ( s === '' || s.length > 254 ) {
			return false;
		}
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( s );
	}

	/**
	 * @param {*} raw
	 * @return {Array<string>}
	 */
	function bdfrmsNormalizeEmailRecipientsArray( raw ) {
		var parts = [];
		if ( Array.isArray( raw ) ) {
			parts = raw;
		} else if ( typeof raw === 'string' && raw.trim() !== '' ) {
			parts = raw.split( /\s*,\s*/ );
		}
		var seen = {};
		var out = [];
		parts.forEach( function ( part ) {
			var email = part == null ? '' : String( part ).trim();
			if ( ! bdfrmsIsValidEmailToken( email ) ) {
				return;
			}
			var key = email.toLowerCase();
			if ( seen[ key ] ) {
				return;
			}
			seen[ key ] = true;
			out.push( email );
		} );
		return out;
	}

	/**
	 * @return {string}
	 */
	function bdfrmsGetDefaultAdminEmail() {
		if ( typeof bdfrmsEditorAssets === 'undefined' || ! bdfrmsEditorAssets.adminEmail ) {
			return '';
		}
		var email = String( bdfrmsEditorAssets.adminEmail ).trim();
		return bdfrmsIsValidEmailToken( email ) ? email : '';
	}

	/**
	 * Nach Verlassen des Formularblocks: leeres Empfänger-Feld → Admin-E-Mail wieder setzen.
	 *
	 * @param {object} attributes
	 * @param {function} setAttributes
	 * @param {string} clientId
	 */
	function syncEmailRecipientsOnFormBlur( attributes, setAttributes, clientId ) {
		var formHasFocus = useSelect(
			function ( select ) {
				var blockEditor = select( 'core/block-editor' );
				return (
					blockEditor.isBlockSelected( clientId ) ||
					blockEditor.hasSelectedInnerBlock( clientId, true )
				);
			},
			[ clientId ]
		);
		var hadFocus = useRef( false );
		useEffect(
			function () {
				if ( hadFocus.current && ! formHasFocus ) {
					if ( bdfrmsNormalizeEmailRecipientsArray( attributes.emailRecipients ).length === 0 ) {
						var admin = bdfrmsGetDefaultAdminEmail();
						if ( admin ) {
							setAttributes( { emailRecipients: [ admin ] } );
						}
					}
				}
				hadFocus.current = formHasFocus;
			},
			[ formHasFocus, attributes.emailRecipients, setAttributes ]
		);
	}

	/**
	 * @param {object} attributes
	 * @param {function} setAttributes
	 * @param {Array<{name:string,label:string}>} emailFieldRows
	 * @return {*}
	 */
	var BDFRMS_EMAIL_FROM_CUSTOM_SENDER = 'bdfrms_custom_sender';

	function renderEmailNotificationControls( attributes, setAttributes, emailFieldRows ) {
		var enabled = attributes.emailNotificationEnabled === true;
		var fromMode = attributes.emailFromField || '';
		var isCustomFrom = fromMode === BDFRMS_EMAIL_FROM_CUSTOM_SENDER;

		var fromFieldOptions = [
			{
				label: __( 'Admin-E-Mail der Website', 'blitz-donner-forms' ),
				value: '',
			},
			{
				label: __( 'Eigene E-Mail-Adresse', 'blitz-donner-forms' ),
				value: BDFRMS_EMAIL_FROM_CUSTOM_SENDER,
			},
		];
		emailFieldRows.forEach( function ( row ) {
			var optLabel = row.label ? row.label + ' (' + row.name + ')' : row.name;
			fromFieldOptions.push( { label: optLabel, value: row.name } );
		} );


		return el(
			PanelBody,
			{
				title: __( 'E-Mail-Benachrichtigung', 'blitz-donner-forms' ),
				initialOpen: false,
			},
			el( ToggleControl, {
				label: __( 'E-Mail nach Absenden senden', 'blitz-donner-forms' ),
				checked: enabled,
				onChange: function ( value ) {
					setAttributes( { emailNotificationEnabled: value } );
				},
			} ),
			enabled
				? el(
						'div',
						null,
						el( FormTokenField, {
							label: __( 'Empfänger', 'blitz-donner-forms' ),
							value: bdfrmsNormalizeEmailRecipientsArray( attributes.emailRecipients ),
							placeholder: __( 'E-Mail-Adresse eingeben …', 'blitz-donner-forms' ),
							tokenizeOnSpace: true,
							__nextHasNoMarginBottom: true,
							__experimentalValidateInput: bdfrmsIsValidEmailToken,
							messages: {
								__experimentalInvalid: __(
									'Keine gültige E-Mail-Adresse.',
									'blitz-donner-forms'
								),
							},
							onChange: function ( tokens ) {
								setAttributes( {
									emailRecipients: bdfrmsNormalizeEmailRecipientsArray( tokens ),
								} );
							},
						} ),
						el(
							'p',
							{
								className: 'components-base-control__help',
								style: { marginTop: 0 },
							},
							__( 'Leer = Admin-E-Mail der Website.', 'blitz-donner-forms' )
						),
						el( TextControl, {
							label: __( 'Betreff', 'blitz-donner-forms' ),
							help: __(
								'Leer = Standardbetreff. Platzhalter: {{feldname}} und {{label_feldname}} (technischer Feldname).',
								'blitz-donner-forms'
							),
							value: attributes.emailSubject || '',
							onChange: function ( v ) {
								setAttributes( { emailSubject: v == null ? '' : String( v ) } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Absender-E-Mail', 'blitz-donner-forms' ),
							help: __(
								'Admin, feste Adresse oder Wert aus einem E-Mail-Feld der Einsendung (sonst Admin-E-Mail).',
								'blitz-donner-forms'
							),
							value: isCustomFrom ? BDFRMS_EMAIL_FROM_CUSTOM_SENDER : fromMode,
							options: fromFieldOptions,
							onChange: function ( v ) {
								setAttributes( { emailFromField: v || '' } );
							},
						} ),
						isCustomFrom
							? el( TextControl, {
									label: __( 'Eigene Absender-E-Mail', 'blitz-donner-forms' ),
									type: 'email',
									help: __(
										'Feste From-Adresse für diese Benachrichtigung (nicht aus dem Formular). Ungültig oder leer → Admin-E-Mail.',
										'blitz-donner-forms'
									),
									value: attributes.emailFromCustom || '',
									onChange: function ( v ) {
										setAttributes( {
											emailFromCustom: v == null ? '' : String( v ).trim(),
										} );
									},
							  } )
							: null,
						el( TextControl, {
							label: __( 'Absendername', 'blitz-donner-forms' ),
							help: __(
								'Optional. Leer = Name der Website. Platzhalter: {{feldname}} und {{label_feldname}} (technischer Feldname).',
								'blitz-donner-forms'
							),
							value: attributes.emailFromName || '',
							onChange: function ( v ) {
								setAttributes( { emailFromName: v == null ? '' : String( v ) } );
							},
						} )
				  )
				: null
		);
	}

	/**
	 * @param {*} select
	 * @param {string} clientId
	 * @return {Array<{name:string,label:string}>}
	 */
	function bdfrmsTokenRowsFromAncestorForm( select, clientId ) {
		var sel = select( 'core/block-editor' );
		if ( ! sel || ! clientId ) {
			return [];
		}
		var parents = sel.getBlockParents( clientId, true );
		if ( ! parents || ! parents.length ) {
			return [];
		}
		for ( var i = 0; i < parents.length; i++ ) {
			var block = sel.getBlock( parents[ i ] );
			if ( block && block.name === 'bdfrms/form' ) {
				var raw = bdfrmsCollectFormFieldTokenRows( block.innerBlocks || [] );
				var seen = {};
				var out = [];
				raw.forEach( function ( r ) {
					if ( seen[ r.name ] ) {
						return;
					}
					seen[ r.name ] = true;
					out.push( r );
				} );
				return out;
			}
		}
		return [];
	}

	var BDFRMS_FIELD_BLOCKS_ORDERED = [
		'bdfrms/field-text',
		'bdfrms/field-email',
		'bdfrms/field-textarea',
		'bdfrms/field-select',
		'bdfrms/field-checkbox',
		'bdfrms/field-number',
		'bdfrms/field-tel',
		'bdfrms/field-url',
		'bdfrms/field-date',
		'bdfrms/field-time',
		'bdfrms/field-datetime',
		'bdfrms/field-radio',
		'bdfrms/field-range',
		'bdfrms/field-hidden',
		'bdfrms/field-file',
		'bdfrms/field-submit',
	];

	/**
	 * Umlaute und häufige Akzente für technische Namen (POST-Keys) in ASCII approximieren.
	 *
	 * @param {string} s
	 * @return {string}
	 */
	function bdfrmsTransliterateForFieldSlug( s ) {
		var map = {
			ä: 'ae',
			ö: 'oe',
			ü: 'ue',
			Ä: 'ae',
			Ö: 'oe',
			Ü: 'ue',
			ß: 'ss',
			à: 'a',
			á: 'a',
			â: 'a',
			è: 'e',
			é: 'e',
			ê: 'e',
			ì: 'i',
			í: 'i',
			î: 'i',
			ò: 'o',
			ó: 'o',
			ô: 'o',
			ù: 'u',
			ú: 'u',
			û: 'u',
			ç: 'c',
			ñ: 'n',
		};
		var t = String( s );
		var out = '';
		for ( var i = 0; i < t.length; i++ ) {
			var c = t.charAt( i );
			var rep = map[ c ];
			out += typeof rep === 'string' ? rep : c;
		}
		return out;
	}

	/**
	 * Aus Label/Platzhalter einen stabilen Slug (a-z, 0-9, Unterstrich).
	 *
	 * @param {string} raw
	 * @return {string}
	 */
	function bdfrmsSlugifyFieldBase( raw ) {
		var s = bdfrmsTransliterateForFieldSlug( raw );
		s = s.replace( /<[^>]*>/g, '' );
		s = s.toLowerCase().replace( /[^a-z0-9_]+/g, '_' ).replace( /_+/g, '_' ).replace( /^_|_$/g, '' );
		if ( s.length === 0 ) {
			s = 'feld';
		}
		if ( s.length > 48 ) {
			s = s.slice( 0, 48 ).replace( /_+$/g, '' );
		}
		return s;
	}

	/**
	 * @param {string} raw
	 * @return {string}
	 */
	function bdfrmsSanitizeFieldNameInput( raw ) {
		return bdfrmsSlugifyFieldBase( raw == null ? '' : String( raw ) );
	}

	/**
	 * Entfernt alten Auto-Suffix aus clientId (Migration).
	 *
	 * @param {string} name
	 * @return {string}
	 */
	function bdfrmsStripLegacyClientIdSuffixFromFieldName( name ) {
		return String( name ).replace( /_[a-f0-9]{8,12}$/i, '' );
	}

	/**
	 * @param {string} labelTrim
	 * @param {string} placeholderTrim
	 * @param {string} fallbackPrefix
	 * @return {string}
	 */
	function bdfrmsBaseFieldNameFromLabel( labelTrim, placeholderTrim, fallbackPrefix ) {
		var baseSource =
			labelTrim !== ''
				? labelTrim
				: placeholderTrim !== ''
					? placeholderTrim
					: fallbackPrefix;
		return bdfrmsSanitizeFieldNameInput( baseSource );
	}

	/**
	 * @param {object} takenKeys Objekt mit belegten Namen als Keys.
	 * @param {string} base
	 * @return {string}
	 */
	function bdfrmsEnsureUniqueFieldName( takenKeys, base ) {
		var b = base && String( base ).trim() !== '' ? String( base ).trim() : 'feld';
		if ( ! takenKeys[ b ] ) {
			return b;
		}
		var i = 2;
		while ( i < 100 ) {
			var candidate = b + '_' + i;
			if ( ! takenKeys[ candidate ] ) {
				return candidate;
			}
			i += 1;
		}
		return b + '_' + Date.now().toString( 36 ).slice( -6 );
	}

	/**
	 * @param {*} select
	 * @param {string} clientId
	 * @return {string}
	 */
	function bdfrmsFindAncestorFormClientId( select, clientId ) {
		var sel = select( 'core/block-editor' );
		if ( ! sel || ! clientId ) {
			return '';
		}
		var parents = sel.getBlockParents( clientId, true );
		if ( ! parents || ! parents.length ) {
			return '';
		}
		for ( var i = 0; i < parents.length; i++ ) {
			var block = sel.getBlock( parents[ i ] );
			if ( block && block.name === 'bdfrms/form' ) {
				return parents[ i ];
			}
		}
		return '';
	}

	/**
	 * @param {*} select
	 * @param {string} formClientId
	 * @param {string} excludeClientId
	 * @return {object<string,boolean>}
	 */
	function bdfrmsCollectFieldNamesInForm( select, formClientId, excludeClientId ) {
		var taken = {};
		var sel = select( 'core/block-editor' );
		if ( ! sel || ! formClientId ) {
			return taken;
		}
		function walk( blocks ) {
			if ( ! blocks || ! blocks.length ) {
				return;
			}
			blocks.forEach( function ( b ) {
				if ( ! b || ! b.name ) {
					return;
				}
				if ( b.name.indexOf( 'bdfrms/field-' ) === 0 && b.name !== 'bdfrms/field-submit' ) {
					if ( excludeClientId && b.clientId === excludeClientId ) {
						return;
					}
					var nm = b.attributes && b.attributes.name != null ? String( b.attributes.name ).trim() : '';
					if ( nm ) {
						taken[ nm ] = true;
					}
				}
				if ( b.innerBlocks && b.innerBlocks.length ) {
					walk( b.innerBlocks );
				}
			} );
		}
		var formBlock = sel.getBlock( formClientId );
		if ( formBlock ) {
			walk( formBlock.innerBlocks || [] );
		}
		return taken;
	}

	/**
	 * @param {*} select
	 * @param {string} clientId
	 * @param {string} fieldName
	 * @return {boolean}
	 */
	function bdfrmsIsDuplicateFieldNameInForm( select, clientId, fieldName ) {
		var nm = fieldName == null ? '' : String( fieldName ).trim();
		if ( nm === '' ) {
			return false;
		}
		var formClientId = bdfrmsFindAncestorFormClientId( select, clientId );
		if ( ! formClientId ) {
			return false;
		}
		var count = 0;
		var sel = select( 'core/block-editor' );
		function walk( blocks ) {
			if ( ! blocks || ! blocks.length ) {
				return;
			}
			blocks.forEach( function ( b ) {
				if ( ! b || ! b.name ) {
					return;
				}
				if ( b.name.indexOf( 'bdfrms/field-' ) === 0 && b.name !== 'bdfrms/field-submit' ) {
					var n = b.attributes && b.attributes.name != null ? String( b.attributes.name ).trim() : '';
					if ( n === nm ) {
						count += 1;
					}
				}
				if ( b.innerBlocks && b.innerBlocks.length ) {
					walk( b.innerBlocks );
				}
			} );
		}
		var formBlock = sel.getBlock( formClientId );
		if ( formBlock ) {
			walk( formBlock.innerBlocks || [] );
		}
		return count > 1;
	}

	/**
	 * Vergibt stabilen technischen Namen: leer/Legacy/Duplikat → einmalig; sonst unverändert.
	 *
	 * @param {Record<string, unknown>} attributes
	 * @param {function(Record<string, unknown>):void} setAttributes
	 * @param {string} clientId
	 * @param {boolean} includePlaceholder
	 * @param {string} fallbackPrefix
	 * @param {string[]} legacyNames
	 */
	function syncFieldNameBinding( attributes, setAttributes, clientId, includePlaceholder, fallbackPrefix, legacyNames ) {
		useEffect(
			function () {
				var bound = attributes.nameClientId != null ? String( attributes.nameClientId ) : '';
				var current = attributes.name != null ? String( attributes.name ).trim() : '';
				var legacy = legacyNames.indexOf( current ) !== -1;
				var needsBind = bound !== clientId || current === '' || legacy;
				if ( ! needsBind ) {
					return;
				}

				var select = wp.data.select;
				var formClientId = bdfrmsFindAncestorFormClientId( select, clientId );
				if ( ! formClientId ) {
					return;
				}

				var lab = attributes.label != null ? String( attributes.label ).trim() : '';
				var ph = '';
				if ( includePlaceholder && attributes.placeholder != null ) {
					ph = String( attributes.placeholder ).trim();
				}

				var base;
				if ( current !== '' && ! legacy ) {
					base = bdfrmsStripLegacyClientIdSuffixFromFieldName( current );
				} else {
					base = bdfrmsBaseFieldNameFromLabel( lab, ph, fallbackPrefix );
				}

				var taken = bdfrmsCollectFieldNamesInForm( select, formClientId, clientId );
				var unique = bdfrmsEnsureUniqueFieldName( taken, base );
				setAttributes( { name: unique, nameClientId: clientId } );
			},
			[
				attributes.label,
				attributes.placeholder,
				attributes.name,
				attributes.nameClientId,
				clientId,
				setAttributes,
				includePlaceholder,
				fallbackPrefix,
				legacyNames,
			]
		);
	}

	/**
	 * Inspector: technischer Feldname + Duplikat-Hinweis innerhalb des Formulars.
	 *
	 * @param {object} props
	 * @return {*}
	 */
	function GfbFieldNameInspector( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var clientId = props.clientId;
		var name = attributes.name != null ? String( attributes.name ).trim() : '';
		var isDuplicate = useSelect(
			function ( select ) {
				return bdfrmsIsDuplicateFieldNameInForm( select, clientId, name );
			},
			[ clientId, name ]
		);

		return el(
			wp.element.Fragment,
			null,
			el( TextControl, {
				label: __( 'Eindeutiger Feldname', 'blitz-donner-forms' ),
				value: attributes.name || '',
				onChange: function ( value ) {
					var next = bdfrmsSanitizeFieldNameInput( value );
					setAttributes( { name: next, nameClientId: clientId } );
				},
			} ),
			isDuplicate
				? el( Notice, {
						status: 'error',
						isDismissible: false,
				  }, __( 'Dieser Feldname existiert in diesem Formular bereits. Bitte anpassen, sonst schlägt das Absenden fehl.', 'blitz-donner-forms' ) )
				: null
		);
	}

	function buildFieldControls( attributes, setAttributes, includePlaceholder ) {
		var controls = [
			el(
				TextControl,
				{
					label: __( 'Label', 'blitz-donner-forms' ),
					value: attributes.label || '',
					onChange: function ( value ) {
						setAttributes( { label: value } );
					},
				}
			),
		];

		if ( includePlaceholder ) {
			controls.push(
				el( TextControl, {
					label: __( 'Platzhalter', 'blitz-donner-forms' ),
					value: attributes.placeholder || '',
					onChange: function ( value ) {
						setAttributes( { placeholder: value } );
					},
				} )
			);
		}

		controls.push(
			el( ToggleControl, {
				label: __( 'Pflichtfeld', 'blitz-donner-forms' ),
				checked: !! attributes.required,
				onChange: function ( value ) {
					setAttributes( { required: value } );
				},
			} )
		);

		// Vertraulich-Flag: Der Toggle erscheint erst, wenn ein Add-on die
		// Markierung auswertet (Filter bdfrms_sensitive_ui_active, via
		// bdfrmsEditorAssets.sensitiveUi). Die Basis speichert Klartext.
		var sensitiveUiActive =
			typeof bdfrmsEditorAssets !== 'undefined' && '1' === String( bdfrmsEditorAssets.sensitiveUi );
		if ( sensitiveUiActive && typeof attributes.sensitive !== 'undefined' ) {
			controls.push(
				el(
					ToggleControl,
					{
						label: __( 'Vertraulich', 'blitz-donner-forms' ),
						help: __(
							'Markiert das Feld als vertraulich. Maskierung und Verschlüsselung übernimmt das Security-Add-on, falls installiert. Beim Versand per Mail bleibt der Wert sichtbar.',
							'blitz-donner-forms'
						),
						checked: !! attributes.sensitive,
						onChange: function ( value ) {
							setAttributes( { sensitive: !! value } );
						},
					}
				)
			);
		}

		return controls;
	}

	function buildMinMaxStepInspector( attributes, setAttributes, includeStep ) {
		var rows = [
			el( TextControl, {
				label: __( 'Min', 'blitz-donner-forms' ),
				value: attributes.min || '',
				onChange: function ( v ) {
					setAttributes( { min: v } );
				},
			} ),
			el( TextControl, {
				label: __( 'Max', 'blitz-donner-forms' ),
				value: attributes.max || '',
				onChange: function ( v ) {
					setAttributes( { max: v } );
				},
			} ),
		];
		if ( includeStep ) {
			rows.push(
				el( TextControl, {
					label: __( 'Schritt', 'blitz-donner-forms' ),
					value: attributes.step || '',
					onChange: function ( v ) {
						setAttributes( { step: v } );
					},
				} )
			);
		}
		return rows;
	}

	function buildDateMinMaxInspector( attributes, setAttributes ) {
		return [
			el( TextControl, {
				label: __( 'Frühestes Datum', 'blitz-donner-forms' ),
				value: attributes.min || '',
				onChange: function ( v ) {
					setAttributes( { min: v } );
				},
			} ),
			el( TextControl, {
				label: __( 'Spätestes Datum', 'blitz-donner-forms' ),
				value: attributes.max || '',
				onChange: function ( v ) {
					setAttributes( { max: v } );
				},
			} ),
		];
	}

	/**
	 * @param {Record<string, unknown>} attributes
	 * @param {function(Record<string, unknown>):void} setAttributes
	 * @param {string} help
	 */
	function buildOptionalDefaultValueControl( attributes, setAttributes, help ) {
		return el( TextControl, {
			label: __( 'Voreingestellter Wert (optional)', 'blitz-donner-forms' ),
			help: help,
			value: attributes.defaultValue != null ? String( attributes.defaultValue ) : '',
			onChange: function ( v ) {
				setAttributes( { defaultValue: v != null ? String( v ) : '' } );
			},
		} );
	}

	function formHasCustomColors( attrs ) {
		return !!(
			( attrs.colorLabel && String( attrs.colorLabel ).trim() ) ||
			( attrs.colorText && String( attrs.colorText ).trim() ) ||
			( attrs.colorPlaceholder && String( attrs.colorPlaceholder ).trim() ) ||
			( attrs.colorFieldBg && String( attrs.colorFieldBg ).trim() ) ||
			( attrs.colorBorder && String( attrs.colorBorder ).trim() ) ||
			( attrs.colorFocus && String( attrs.colorFocus ).trim() ) ||
			( attrs.colorButtonBg && String( attrs.colorButtonBg ).trim() ) ||
			( attrs.colorButtonText && String( attrs.colorButtonText ).trim() ) ||
			( attrs.darkColorLabel && String( attrs.darkColorLabel ).trim() ) ||
			( attrs.darkColorText && String( attrs.darkColorText ).trim() ) ||
			( attrs.darkColorPlaceholder && String( attrs.darkColorPlaceholder ).trim() ) ||
			( attrs.darkColorFieldBg && String( attrs.darkColorFieldBg ).trim() ) ||
			( attrs.darkColorBorder && String( attrs.darkColorBorder ).trim() ) ||
			( attrs.darkColorFocus && String( attrs.darkColorFocus ).trim() ) ||
			( attrs.darkColorButtonBg && String( attrs.darkColorButtonBg ).trim() ) ||
			( attrs.darkColorButtonText && String( attrs.darkColorButtonText ).trim() ) ||
			( attrs.colorFormShell && String( attrs.colorFormShell ).trim() ) ||
			( attrs.darkColorFormShell && String( attrs.darkColorFormShell ).trim() )
		);
	}

	/**
	 * Welche Palette im Editor für Feld-Overrides genutzt wird (bei „Automatisch“: System).
	 *
	 * @param {string|undefined} appearanceMode
	 * @return {'light'|'dark'}
	 */
	function resolveActivePalette( appearanceMode ) {
		var mode = appearanceMode || 'auto';
		if ( mode === 'light' ) {
			return 'light';
		}
		if ( mode === 'dark' ) {
			return 'dark';
		}
		if (
			typeof window !== 'undefined' &&
			window.matchMedia &&
			window.matchMedia( '(prefers-color-scheme: dark)' ).matches
		) {
			return 'dark';
		}
		return 'light';
	}

	function mergeFieldColorAttrs( fieldAttrs, ctx ) {
		ctx = ctx || {};
		var mode = ctx['bdfrms/appearanceMode'] || 'auto';
		function pickTheme( key, ctxLight, ctxDark ) {
			var fv = fieldAttrs[ key ];
			if ( fv && String( fv ).trim() !== '' ) {
				return fv;
			}
			var light = ctx[ ctxLight ];
			var dark = ctx[ ctxDark ];
			if ( light && String( light ).trim() !== '' ) {
				return light;
			}
			if ( dark && String( dark ).trim() !== '' ) {
				return dark;
			}
			return '';
		}
		if ( mode === 'theme' ) {
			return {
				colorLabel: pickTheme( 'colorLabel', 'bdfrms/colorLabel', 'bdfrms/darkColorLabel' ),
				colorText: pickTheme( 'colorText', 'bdfrms/colorText', 'bdfrms/darkColorText' ),
				colorPlaceholder: pickTheme( 'colorPlaceholder', 'bdfrms/colorPlaceholder', 'bdfrms/darkColorPlaceholder' ),
				colorFieldBg: pickTheme( 'colorFieldBg', 'bdfrms/colorFieldBg', 'bdfrms/darkColorFieldBg' ),
				colorBorder: pickTheme( 'colorBorder', 'bdfrms/colorBorder', 'bdfrms/darkColorBorder' ),
				colorFocus: pickTheme( 'colorFocus', 'bdfrms/colorFocus', 'bdfrms/darkColorFocus' ),
				colorButtonBg: pickTheme( 'colorButtonBg', 'bdfrms/colorButtonBg', 'bdfrms/darkColorButtonBg' ),
				colorButtonText: pickTheme( 'colorButtonText', 'bdfrms/colorButtonText', 'bdfrms/darkColorButtonText' ),
			};
		}
		var palette = resolveActivePalette( mode );
		function pick( key, ctxLight, ctxDark ) {
			var fv = fieldAttrs[ key ];
			if ( fv && String( fv ).trim() !== '' ) {
				return fv;
			}
			var light = ctx[ ctxLight ];
			var dark = ctx[ ctxDark ];
			if ( palette === 'dark' ) {
				if ( dark && String( dark ).trim() !== '' ) {
					return dark;
				}
				return light && String( light ).trim() !== '' ? light : '';
			}
			if ( light && String( light ).trim() !== '' ) {
				return light;
			}
			return dark && String( dark ).trim() !== '' ? dark : '';
		}
		return {
			colorLabel: pick( 'colorLabel', 'bdfrms/colorLabel', 'bdfrms/darkColorLabel' ),
			colorText: pick( 'colorText', 'bdfrms/colorText', 'bdfrms/darkColorText' ),
			colorPlaceholder: pick( 'colorPlaceholder', 'bdfrms/colorPlaceholder', 'bdfrms/darkColorPlaceholder' ),
			colorFieldBg: pick( 'colorFieldBg', 'bdfrms/colorFieldBg', 'bdfrms/darkColorFieldBg' ),
			colorBorder: pick( 'colorBorder', 'bdfrms/colorBorder', 'bdfrms/darkColorBorder' ),
			colorFocus: pick( 'colorFocus', 'bdfrms/colorFocus', 'bdfrms/darkColorFocus' ),
			colorButtonBg: pick( 'colorButtonBg', 'bdfrms/colorButtonBg', 'bdfrms/darkColorButtonBg' ),
			colorButtonText: pick( 'colorButtonText', 'bdfrms/colorButtonText', 'bdfrms/darkColorButtonText' ),
		};
	}

	function colorAttrsToStyleObject( attrs ) {
		var o = {};
		if ( attrs.colorLabel && String( attrs.colorLabel ).trim() !== '' ) {
			o['--bdfrms-label'] = attrs.colorLabel;
		}
		if ( attrs.colorText && String( attrs.colorText ).trim() !== '' ) {
			o['--bdfrms-text'] = attrs.colorText;
		}
		if ( attrs.colorPlaceholder && String( attrs.colorPlaceholder ).trim() !== '' ) {
			o['--bdfrms-placeholder'] = attrs.colorPlaceholder;
		}
		if ( attrs.colorFieldBg && String( attrs.colorFieldBg ).trim() !== '' ) {
			o['--bdfrms-bg'] = attrs.colorFieldBg;
		}
		if ( attrs.colorBorder && String( attrs.colorBorder ).trim() !== '' ) {
			o['--bdfrms-border'] = attrs.colorBorder;
		}
		if ( attrs.colorFocus && String( attrs.colorFocus ).trim() !== '' ) {
			o['--bdfrms-border-focus'] = attrs.colorFocus;
		}
		if ( attrs.colorButtonBg && String( attrs.colorButtonBg ).trim() !== '' ) {
			o['--bdfrms-submit-bg'] = attrs.colorButtonBg;
		}
		if ( attrs.colorButtonText && String( attrs.colorButtonText ).trim() !== '' ) {
			o['--bdfrms-submit-text'] = attrs.colorButtonText;
		}
		return Object.keys( o ).length ? o : undefined;
	}

	/**
	 * Inline-Styles für .bdfrms-form-wrapper (wie PHP): Hell- und Dunkel-Variablen.
	 *
	 * @param {Object} attrs Formular-Attribute.
	 * @return {Object|undefined}
	 */
	function formWrapperColorStyleObject( attrs ) {
		var o = {};
		var lightPairs = [
			[ 'colorLabel', '--bdfrms-light-label' ],
			[ 'colorText', '--bdfrms-light-text' ],
			[ 'colorPlaceholder', '--bdfrms-light-placeholder' ],
			[ 'colorFieldBg', '--bdfrms-light-bg' ],
			[ 'colorBorder', '--bdfrms-light-border' ],
			[ 'colorFocus', '--bdfrms-light-border-focus' ],
			[ 'colorButtonBg', '--bdfrms-light-submit-bg' ],
			[ 'colorButtonText', '--bdfrms-light-submit-text' ],
			[ 'colorFormShell', '--bdfrms-light-form-shell' ],
		];
		var darkPairs = [
			[ 'darkColorLabel', '--bdfrms-dark-label' ],
			[ 'darkColorText', '--bdfrms-dark-text' ],
			[ 'darkColorPlaceholder', '--bdfrms-dark-placeholder' ],
			[ 'darkColorFieldBg', '--bdfrms-dark-bg' ],
			[ 'darkColorBorder', '--bdfrms-dark-border' ],
			[ 'darkColorFocus', '--bdfrms-dark-border-focus' ],
			[ 'darkColorButtonBg', '--bdfrms-dark-submit-bg' ],
			[ 'darkColorButtonText', '--bdfrms-dark-submit-text' ],
			[ 'darkColorFormShell', '--bdfrms-dark-form-shell' ],
		];
		lightPairs.forEach( function ( pair ) {
			var v = attrs[ pair[ 0 ] ];
			if ( v && String( v ).trim() !== '' ) {
				o[ pair[ 1 ] ] = v;
			}
		} );
		darkPairs.forEach( function ( pair ) {
			var v = attrs[ pair[ 0 ] ];
			if ( v && String( v ).trim() !== '' ) {
				o[ pair[ 1 ] ] = v;
			}
		} );
		return Object.keys( o ).length ? o : undefined;
	}

	function buildMergedFieldColorStyle( fieldAttrs, ctx ) {
		return colorAttrsToStyleObject( mergeFieldColorAttrs( fieldAttrs, ctx ) );
	}

	function buildFieldColorOverrideStyle( fieldAttrs ) {
		return colorAttrsToStyleObject( {
			colorLabel: fieldAttrs.colorLabel || '',
			colorText: fieldAttrs.colorText || '',
			colorPlaceholder: fieldAttrs.colorPlaceholder || '',
			colorFieldBg: fieldAttrs.colorFieldBg || '',
			colorBorder: fieldAttrs.colorBorder || '',
			colorFocus: fieldAttrs.colorFocus || '',
			colorButtonBg: fieldAttrs.colorButtonBg || '',
			colorButtonText: fieldAttrs.colorButtonText || '',
		} );
	}

	/**
	 * @param {unknown} label
	 * @return {string}
	 */
	function bdfrmsTrimmedFieldLabel( label ) {
		if ( label == null ) {
			return '';
		}
		return String( label ).trim();
	}

	/**
	 * Editor-Vorschau: kein Platzhalter-Label — nur rendern, wenn Text gesetzt.
	 *
	 * @param {string} tag
	 * @param {Record<string, unknown>|null|undefined} props
	 * @param {unknown} labelAttr
	 * @return {*|null}
	 */
	function bdfrmsEditorLabelIfAny( tag, props, labelAttr, required ) {
		var t = bdfrmsTrimmedFieldLabel( labelAttr );
		if ( ! t ) {
			return null;
		}
		if ( required ) {
			return el( tag, props || null, t, el( 'span', { className: 'bdfrms-required', 'aria-hidden': 'true' }, ' *' ) );
		}
		return el( tag, props || null, t );
	}

	/**
	 * @param {unknown} nameAttr
	 * @return {{ for: string }|null}
	 */
	function bdfrmsLabelForProps( nameAttr ) {
		var n = nameAttr != null && String( nameAttr ).trim() !== '' ? String( nameAttr ).trim() : '';
		return n ? { for: n } : null;
	}

	/**
	 * Save-Markup: <label for> nur wenn nicht leer (nach trim).
	 *
	 * @param {string} forId
	 * @param {unknown} labelAttr
	 * @return {*|null}
	 */
	function bdfrmsSaveLabelIfAny( forId, labelAttr ) {
		var t = bdfrmsTrimmedFieldLabel( labelAttr );
		if ( ! t ) {
			return null;
		}
		return el( 'label', { for: forId }, t );
	}

	/**
	 * @param {Array<{ name?: string, attributes?: Record<string, unknown>, innerBlocks?: unknown[] }>|undefined} blocks
	 * @return {boolean}
	 */
	/** Irgendein bdfrms/form-Block → Editor-Stylesheet für Theme-Vorschau nötig. */
	function bdfrmsEditorBlockTreeHasAnyGfbForm( blocks ) {
		if ( ! blocks || ! blocks.length ) {
			return false;
		}
		for ( var i = 0; i < blocks.length; i++ ) {
			var b = blocks[ i ];
			if ( b.name === 'bdfrms/form' ) {
				return true;
			}
			if ( b.innerBlocks && b.innerBlocks.length && bdfrmsEditorBlockTreeHasAnyGfbForm( b.innerBlocks ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param {(doc: Document) => void} callback
	 */
	function bdfrmsForEachEditorCanvasDocument( callback ) {
		var seen = [];
		function visit( doc ) {
			if ( ! doc || ! doc.head || seen.indexOf( doc ) !== -1 ) {
				return;
			}
			var hasCanvas =
				( doc.querySelector && doc.querySelector( '.editor-styles-wrapper' ) ) ||
				( doc.querySelector && doc.querySelector( '.editor-canvas__iframe-body' ) ) ||
				( doc.querySelector && doc.querySelector( '.is-root-container' ) );
			if ( hasCanvas ) {
				seen.push( doc );
				callback( doc );
			}
		}
		visit( document );
		var iframes = document.querySelectorAll( 'iframe' );
		for ( var j = 0; j < iframes.length; j++ ) {
			try {
				var idoc = iframes[ j ].contentDocument;
				visit( idoc );
			} catch ( err ) {
				/* Cross-Origin */
			}
		}
	}

	function bdfrmsSyncEditorFormStylesheet() {
		var assets = typeof window !== 'undefined' ? window.bdfrmsEditorAssets : null;
		var formBase =
			assets && assets.editorCanvasFormStylesUrl ? assets.editorCanvasFormStylesUrl : '';
		var chromeBase =
			assets && assets.editorChromeStylesUrl
				? assets.editorChromeStylesUrl
				: assets && assets.editorFormStylesUrl
					? assets.editorFormStylesUrl
					: '';
		if ( ! assets || ( ! formBase && ! chromeBase ) ) {
			return;
		}
		var needs = false;
		try {
			var sel = wp.data.select( 'core/block-editor' );
			if ( sel && typeof sel.getBlocks === 'function' ) {
				needs = bdfrmsEditorBlockTreeHasAnyGfbForm( sel.getBlocks() );
			}
		} catch ( e0 ) {
			needs = false;
		}
		var ver = assets.version ? encodeURIComponent( String( assets.version ) ) : '';
		function withVer( base ) {
			if ( ! base ) {
				return '';
			}
			var sep = base.indexOf( '?' ) === -1 ? '?' : '&';
			return base + ( ver ? sep + 'ver=' + ver : '' );
		}
		var formHref = withVer( formBase );
		var chromeHref = withVer( chromeBase );
		bdfrmsForEachEditorCanvasDocument( function ( doc ) {
			function upsertLink( id, href ) {
				if ( ! href ) {
					var old = doc.getElementById( id );
					if ( old && old.parentNode ) {
						old.parentNode.removeChild( old );
					}
					return;
				}
				if ( ! needs ) {
					var rm = doc.getElementById( id );
					if ( rm && rm.parentNode ) {
						rm.parentNode.removeChild( rm );
					}
					return;
				}
				var existing = doc.getElementById( id );
				if ( ! existing ) {
					var link = doc.createElement( 'link' );
					link.id = id;
					link.rel = 'stylesheet';
					link.href = href;
					doc.head.appendChild( link );
				} else if ( existing.getAttribute( 'href' ) !== href ) {
					existing.setAttribute( 'href', href );
				}
			}
			upsertLink( 'bdfrms-editor-canvas-form-stylesheet', formHref );
			upsertLink( 'bdfrms-editor-chrome-stylesheet', chromeHref );
			/* Alte Einbindung (ein Stylesheet) entfernen. */
			var legacy = doc.getElementById( 'bdfrms-editor-form-stylesheet' );
			if ( legacy && legacy.parentNode ) {
				legacy.parentNode.removeChild( legacy );
			}
		} );
	}

	function createFormColorSettings( attributes, setAttributes ) {
		var appearance = attributes.appearanceMode || 'auto';
		var rows = [
			{
				label: __( 'Label', 'blitz-donner-forms' ),
				value: attributes.colorLabel || '',
				onChange: function ( v ) {
					setAttributes( { colorLabel: v || '' } );
				},
			},
			{
				label: __( 'Eingabetext', 'blitz-donner-forms' ),
				value: attributes.colorText || '',
				onChange: function ( v ) {
					setAttributes( { colorText: v || '' } );
				},
			},
			{
				label: __( 'Platzhalter', 'blitz-donner-forms' ),
				value: attributes.colorPlaceholder || '',
				onChange: function ( v ) {
					setAttributes( { colorPlaceholder: v || '' } );
				},
			},
			{
				label: __( 'Feldhintergrund', 'blitz-donner-forms' ),
				value: attributes.colorFieldBg || '',
				onChange: function ( v ) {
					setAttributes( { colorFieldBg: v || '' } );
				},
			},
			{
				label: __( 'Rahmen', 'blitz-donner-forms' ),
				value: attributes.colorBorder || '',
				onChange: function ( v ) {
					setAttributes( { colorBorder: v || '' } );
				},
			},
			{
				label: __( 'Fokus (Rahmen, Schieberegler)', 'blitz-donner-forms' ),
				value: attributes.colorFocus || '',
				onChange: function ( v ) {
					setAttributes( { colorFocus: v || '' } );
				},
			},
			{
				label: __( 'Button-Hintergrund', 'blitz-donner-forms' ),
				value: attributes.colorButtonBg || '',
				onChange: function ( v ) {
					setAttributes( { colorButtonBg: v || '' } );
				},
			},
			{
				label: __( 'Button-Text', 'blitz-donner-forms' ),
				value: attributes.colorButtonText || '',
				onChange: function ( v ) {
					setAttributes( { colorButtonText: v || '' } );
				},
			},
		];
		if ( appearance !== 'theme' ) {
			rows.push( {
				label: __( 'Hintergrund Formularbereich', 'blitz-donner-forms' ),
				value: attributes.colorFormShell || '',
				onChange: function ( v ) {
					setAttributes( { colorFormShell: v || '' } );
				},
			} );
		}
		return rows;
	}

	function createDarkFormColorSettings( attributes, setAttributes ) {
		var appearance = attributes.appearanceMode || 'auto';
		var rows = [
			{
				label: __( 'Label', 'blitz-donner-forms' ),
				value: attributes.darkColorLabel || '',
				onChange: function ( v ) {
					setAttributes( { darkColorLabel: v || '' } );
				},
			},
			{
				label: __( 'Eingabetext', 'blitz-donner-forms' ),
				value: attributes.darkColorText || '',
				onChange: function ( v ) {
					setAttributes( { darkColorText: v || '' } );
				},
			},
			{
				label: __( 'Platzhalter', 'blitz-donner-forms' ),
				value: attributes.darkColorPlaceholder || '',
				onChange: function ( v ) {
					setAttributes( { darkColorPlaceholder: v || '' } );
				},
			},
			{
				label: __( 'Feldhintergrund', 'blitz-donner-forms' ),
				value: attributes.darkColorFieldBg || '',
				onChange: function ( v ) {
					setAttributes( { darkColorFieldBg: v || '' } );
				},
			},
			{
				label: __( 'Rahmen', 'blitz-donner-forms' ),
				value: attributes.darkColorBorder || '',
				onChange: function ( v ) {
					setAttributes( { darkColorBorder: v || '' } );
				},
			},
			{
				label: __( 'Fokus (Rahmen, Schieberegler)', 'blitz-donner-forms' ),
				value: attributes.darkColorFocus || '',
				onChange: function ( v ) {
					setAttributes( { darkColorFocus: v || '' } );
				},
			},
			{
				label: __( 'Button-Hintergrund', 'blitz-donner-forms' ),
				value: attributes.darkColorButtonBg || '',
				onChange: function ( v ) {
					setAttributes( { darkColorButtonBg: v || '' } );
				},
			},
			{
				label: __( 'Button-Text', 'blitz-donner-forms' ),
				value: attributes.darkColorButtonText || '',
				onChange: function ( v ) {
					setAttributes( { darkColorButtonText: v || '' } );
				},
			},
		];
		if ( appearance !== 'theme' ) {
			rows.push( {
				label: __( 'Hintergrund Formularbereich', 'blitz-donner-forms' ),
				value: attributes.darkColorFormShell || '',
				onChange: function ( v ) {
					setAttributes( { darkColorFormShell: v || '' } );
				},
			} );
		}
		return rows;
	}

	registerBlockType( 'bdfrms/form', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var appearance = attributes.appearanceMode || 'auto';
			var wrapperClassNames = [ 'bdfrms-form-wrapper' ];
			if ( appearance === 'theme' && formHasCustomColors( attributes ) ) {
				wrapperClassNames.push( 'bdfrms-form-colors-custom' );
			}
			if ( appearance !== 'theme' ) {
				if ( bdfrmsAttrLooksLikeCssGradient( attributes.colorFormShell ) ) {
					wrapperClassNames.push( 'bdfrms-form-shell-gradient--light' );
				}
				if ( bdfrmsAttrLooksLikeCssGradient( attributes.darkColorFormShell ) ) {
					wrapperClassNames.push( 'bdfrms-form-shell-gradient--dark' );
				}
			}
			var formColorStyle = formWrapperColorStyleObject( attributes );
			var formBlockProps = useBlockProps( {
				className: wrapperClassNames.join( ' ' ),
				style: formColorStyle,
				'data-bdfrms-appearance': appearance,
			} );

			var allowedInnerBlocks = useSelect( function ( select ) {
				try {
					var types = select( 'core/blocks' ).getBlockTypes();
					if ( ! types || ! types.length ) {
						return true;
					}
					var names = types
						.map( function ( t ) {
							return t.name;
						} )
						.filter( function ( name ) {
							return name !== 'bdfrms/form';
						} );
					var fieldSet = {};
					BDFRMS_FIELD_BLOCKS_ORDERED.forEach( function ( n ) {
						fieldSet[ n ] = true;
					} );
					var fieldsFirst = BDFRMS_FIELD_BLOCKS_ORDERED.filter( function ( n ) {
						return names.indexOf( n ) !== -1;
					} );
					var rest = names
						.filter( function ( n ) {
							return ! fieldSet[ n ] && n !== 'bdfrms/form-success';
						} )
						.sort();
					return fieldsFirst.concat( [ 'bdfrms/form-success' ] ).concat( rest );
				} catch ( err ) {
					return true;
				}
			}, [] );

			var publishedPages = useSelect( function ( select ) {
				try {
					return select( 'core' ).getEntityRecords( 'postType', 'page', {
						per_page: 100,
						status: 'publish',
						orderby: 'title',
						order: 'asc',
					} );
				} catch ( err2 ) {
					return null;
				}
			}, [] );

			var emailFieldRows = useSelect(
				function ( select ) {
					try {
						var block = select( 'core/block-editor' ).getBlock( props.clientId );
						if ( ! block ) {
							return [];
						}
						var raw = bdfrmsCollectFormEmailFieldRows( block.innerBlocks || [] );
						var seen = {};
						var out = [];
						raw.forEach( function ( r ) {
							if ( seen[ r.name ] ) {
								return;
							}
							seen[ r.name ] = true;
							out.push( r );
						} );
						return out;
					} catch ( err3 ) {
						return [];
					}
				},
				[ props.clientId ]
			);

			var thankYouPageOptions = [
				{
					label: __( 'Formularseite mit Erfolgshinweis (Standard)', 'blitz-donner-forms' ),
					value: '',
				},
			];
			if ( publishedPages && Array.isArray( publishedPages ) ) {
				publishedPages.forEach( function ( p ) {
					var title = p.title && p.title.rendered ? p.title.rendered : '#' + String( p.id );
					thankYouPageOptions.push( { label: title, value: String( p.id ) } );
				} );
			}

			syncFormInstance( attributes, setAttributes, props.clientId );
			syncEmailRecipientsOnFormBlur( attributes, setAttributes, props.clientId );

			var innerBlocksProps = useInnerBlocksProps(
				{
					className: 'bdfrms-form-fields',
				},
				{
					allowedBlocks: allowedInnerBlocks,
					template: [
						[ 'bdfrms/field-text' ],
						[ 'bdfrms/field-email' ],
						[ 'bdfrms/field-submit' ],
					],
					templateLock: false,
				}
			);

			return el(
				'div',
				formBlockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __( 'Formular', 'blitz-donner-forms' ),
							initialOpen: false,
						},
						el(
							'p',
							{
								className: 'bdfrms-editor-sidebar-form-id',
								style: {
									marginTop: 0,
									marginBottom: 0,
									fontSize: '11px',
									lineHeight: 1.45,
								},
							},
							__( 'Form-ID', 'blitz-donner-forms' ),
							el( 'br', null ),
							el(
								'code',
								{
									style: {
										display: 'block',
										marginTop: '4px',
										fontSize: '11px',
										wordBreak: 'break-all',
									},
								},
								attributes.formId || '—'
							),
							el(
								'p',
								{
									className: 'components-base-control__help',
									style: { marginTop: '8px', marginBottom: 0 },
								},
								__(
									'Eindeutige Kennung dieses Formulars (bei Duplizieren des Formularblocks neu). Einsendungen und Payload-Auswertung werden immer dieser ID zugeordnet.',
									'blitz-donner-forms'
								)
							)
						)
					),
					el(
						PanelBody,
						{
							title: __( 'Formulareinstellungen', 'blitz-donner-forms' ),
							initialOpen: true,
						},
						el( TextControl, {
							label: __( 'Anzeigename (optional)', 'blitz-donner-forms' ),
							help: __( 'Nur für die Backend-Übersicht und den Standard-E-Mail-Betreff (wenn kein eigener Betreff gesetzt ist); wird nicht im Formular dargestellt.', 'blitz-donner-forms' ),
							value: attributes.formTitle || '',
							onChange: function ( v ) {
								setAttributes( { formTitle: v == null ? '' : String( v ) } );
							},
						} ),
						// Entwurfsspeicherung: bewusst nur EIN Schalter (Entscheid
						// Stefan 05.07.2026). Die Detail-Attribute restoreMode,
						// draftTtlDays und showDraftReset bleiben im Schema und
						// wirken mit ihren Defaults (auto, 7 Tage, Button an);
						// sie sind über das Block-Markup weiterhin setzbar.
						el( ToggleControl, {
							label: __( 'Lokale Entwurfsspeicherung aktivieren', 'blitz-donner-forms' ),
							help: __( 'Eingaben werden im Browser der Besucherin zwischengespeichert und beim nächsten Besuch automatisch wiederhergestellt. Entwürfe verfallen nach 7 Tagen.', 'blitz-donner-forms' ),
							checked: attributes.draftEnabled !== false,
							onChange: function ( value ) {
								setAttributes( { draftEnabled: value } );
							},
						} ),
						el( Notice, {
							status: 'info',
							isDismissible: false,
						}, __( 'Optional den Block „Rückmeldung“ einfügen: Bei Erfolg ohne Folgeseite erscheint sein Inhalt statt des Formulars. Platzhalter im Text: {{feldname}} (technischer Feldname).', 'blitz-donner-forms' ) ),
						el( SelectControl, {
							label: __( 'Folgeseite nach erfolgreichem Absenden', 'blitz-donner-forms' ),
							help: __( 'Öffentlich sichtbare Seite. Ohne Auswahl bleibt die Besucherin auf der Formularseite (Hinweis oben).', 'blitz-donner-forms' ),
							value: attributes.thankYouPageId ? String( attributes.thankYouPageId ) : '',
							options: thankYouPageOptions,
							onChange: function ( v ) {
								var n = parseInt( v, 10 );
								setAttributes( { thankYouPageId: v === '' || Number.isNaN( n ) ? 0 : n } );
							},
						} )
					),
					renderEmailNotificationControls( attributes, setAttributes, emailFieldRows || [] ),
					el( PanelBody, {
						title: __( 'Spam-Schutz (CAPTCHA)', 'blitz-donner-forms' ),
						initialOpen: false,
					},
					el( SelectControl, {
						label: __( 'CAPTCHA für dieses Formular', 'blitz-donner-forms' ),
						help: __( 'Steuert, ob auf diesem Formular ein CAPTCHA erscheint. Voraussetzung: CAPTCHA ist unter «Sicherheit & Einstellungen» global aktiviert und konfiguriert.', 'blitz-donner-forms' ),
						value: attributes.captchaMode || 'inherit',
						options: [
							{ label: __( 'Von globaler Einstellung übernehmen', 'blitz-donner-forms' ), value: 'inherit' },
							{ label: __( 'Immer an', 'blitz-donner-forms' ), value: 'on' },
							{ label: __( 'Immer aus', 'blitz-donner-forms' ), value: 'off' },
						],
						onChange: function ( value ) {
							setAttributes( { captchaMode: value || 'inherit' } );
						},
					} ) ),
					el( PanelBody, {
						title: __( 'Erscheinungsbild', 'blitz-donner-forms' ),
						initialOpen: false,
					},
					el( SelectControl, {
						label: __( 'Farbmodus', 'blitz-donner-forms' ),
						value: appearance,
						help:
							appearance === 'theme'
								? __( 'Farben und Karte kommen vollständig aus dem Theme. Eigene Formularfarben gibt es nur in den Modi Hell, Dunkel oder Automatisch.', 'blitz-donner-forms' )
								: undefined,
						options: [
							{ label: __( 'Theme (Standard)', 'blitz-donner-forms' ), value: 'theme' },
							{ label: __( 'Automatisch (System)', 'blitz-donner-forms' ), value: 'auto' },
							{ label: __( 'Hell', 'blitz-donner-forms' ), value: 'light' },
							{ label: __( 'Dunkel', 'blitz-donner-forms' ), value: 'dark' },
						],
						onChange: function ( value ) {
							setAttributes( { appearanceMode: value || 'auto' } );
						},
					} ),
					appearance !== 'theme'
						? renderGfbColorPanel(
							__( 'Formularfarben (Hell)', 'blitz-donner-forms' ),
							createFormColorSettings( attributes, setAttributes )
						  )
						: null,
					appearance !== 'theme'
						? renderGfbColorPanel(
							__( 'Formularfarben (Dunkel)', 'blitz-donner-forms' ),
							createDarkFormColorSettings( attributes, setAttributes )
						  )
						: null
					)
				),
				el(
					'form',
					{
						className: 'bdfrms-form',
						onSubmit: function ( ev ) {
							ev.preventDefault();
						},
					},
					el( 'div', innerBlocksProps )
				)
			);
		},
		save: function () {
			return el( InnerBlocks.Content );
		},
		deprecated: [
			{
				attributes: {
					formID: { type: 'string' },
				},
				migrate: function ( oldAttributes ) {
					return {
						formId: oldAttributes.formID || '',
					};
				},
				save: function () {
					return el( InnerBlocks.Content );
				},
			},
		],
	} );

	registerBlockType( 'bdfrms/form-success', {
		supports: {
			innerBlocks: true,
		},
		edit: function () {
			var blockProps = useBlockProps( {
				className: 'bdfrms-form-success-editor',
			} );
			var allowedSuccessInner = useSelect( function ( select ) {
				try {
					var types = select( 'core/blocks' ).getBlockTypes();
					if ( ! types || ! types.length ) {
						return true;
					}
					var names = types
						.map( function ( t ) {
							return t.name;
						} )
						.filter( function ( name ) {
							return (
								name !== 'bdfrms/form' &&
								name !== 'bdfrms/form-success' &&
								name.indexOf( 'bdfrms/field-' ) !== 0
							);
						} );
					return [ 'bdfrms/token' ].concat(
						names.filter( function ( n ) {
							return n !== 'bdfrms/token';
						} )
					);
				} catch ( err ) {
					return true;
				}
			}, [] );
			var innerBlocksProps = useInnerBlocksProps(
				{ className: 'bdfrms-form-success-editor__inner' },
				{
					allowedBlocks: allowedSuccessInner,
					templateLock: false,
				}
			);
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __( 'Rückmeldung', 'blitz-donner-forms' ),
							initialOpen: true,
						},
						el( Notice, { status: 'info', isDismissible: false }, __( 'Dieser Inhalt ersetzt nach erfolgreichem Absenden das Formular, sofern keine Folgeseite gewählt ist. Platzhalter: {{feldname}}; optional {{label_feldname}} (z. B. {{label_email}}).', 'blitz-donner-forms' ) )
					)
				),
				el( 'div', innerBlocksProps )
			);
		},
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

	registerBlockType( 'bdfrms/token', {
		edit: function ( props ) {
			var clientId = props.clientId;
			var replaceBlocks = wp.data.useDispatch( 'core/block-editor' ).replaceBlocks;
			var rows = useSelect(
				function ( select ) {
					return bdfrmsTokenRowsFromAncestorForm( select, clientId );
				},
				[ clientId ]
			);
			var blockProps = useBlockProps( {
				className: 'bdfrms-token-hint-editor',
			} );
			var selectOptions = [
				{
					label: __( 'Feldname wählen …', 'blitz-donner-forms' ),
					value: '',
				},
			].concat(
				rows.map( function ( row ) {
					var valueTok = '{{' + row.name + '}}';
					return { label: row.name, value: valueTok };
				} )
			);
			return el(
				'div',
				blockProps,
				el(
					'p',
					{ className: 'bdfrms-token-hint-editor__intro' },
					__(
						'Liste der technischen Feldnamen (POST-Name). Nach der Auswahl wird der Wert-Platzhalter {{feldname}} an dieser Stelle als Absatz eingefügt.',
						'blitz-donner-forms'
					)
				),
				! rows.length
					? el(
							Notice,
							{ status: 'warning', isDismissible: false },
							__(
								'Keine Felder gefunden. Lege zuerst Felder im Formular ausserhalb dieser Rückmeldung an.',
								'blitz-donner-forms'
							)
					  )
					: el( SelectControl, {
							label: __( 'Feld → Wert-Platzhalter einfügen', 'blitz-donner-forms' ),
							value: '',
							options: selectOptions,
							onChange: function ( chosen ) {
								if ( ! chosen ) {
									return;
								}
								var para = createBlock( 'core/paragraph', { content: chosen } );
								replaceBlocks( [ clientId ], [ para ] );
							},
					  } )
			);
		},
		save: function () {
			return null;
		},
	} );

	registerBlockType( 'bdfrms/field-text', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, true, 'textfeld', BDFRMS_LEGACY_NAMES_TEXT );

			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-text',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Textfeld', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, true )
					)
				),
				bdfrmsEditorLabelIfAny( 'label', bdfrmsLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'text',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					placeholder: attributes.placeholder || '',
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-text',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				bdfrmsSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'text',
					name: a.name,
					id: a.name,
					placeholder: a.placeholder || '',
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'bdfrms/field-email', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, true, 'email', BDFRMS_LEGACY_NAMES_EMAIL );
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-email',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'E-Mail-Feld', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, true )
					)
				),
				bdfrmsEditorLabelIfAny( 'label', bdfrmsLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'email',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					placeholder: attributes.placeholder || '',
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-email',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				bdfrmsSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'email',
					name: a.name,
					id: a.name,
					placeholder: a.placeholder || '',
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'bdfrms/field-textarea', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, true, 'nachricht', BDFRMS_LEGACY_NAMES_TEXTAREA );
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-textarea',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Textbereich', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, true )
					)
				),
				bdfrmsEditorLabelIfAny( 'label', bdfrmsLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'textarea', {
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					placeholder: attributes.placeholder || '',
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-textarea',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				bdfrmsSaveLabelIfAny( a.name, a.label ),
				el( 'textarea', {
					name: a.name,
					id: a.name,
					placeholder: a.placeholder || '',
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'bdfrms/field-select', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'auswahl', BDFRMS_LEGACY_NAMES_SELECT );
			var options = ( attributes.options || '' )
				.split( /\n/ )
				.map( function ( item ) {
					return item.trim();
				} )
				.filter( Boolean );

			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-select',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Auswahlfeld', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						el( TextareaControl, {
							label: __( 'Optionen (eine pro Zeile)', 'blitz-donner-forms' ),
							value: attributes.options || '',
							onChange: function ( value ) {
								setAttributes( { options: value } );
							},
						} )
					)
				),
				bdfrmsEditorLabelIfAny( 'label', bdfrmsLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el(
					'select',
					{
						disabled: true,
						id: attributes.name || undefined,
						name: attributes.name || undefined,
					},
					options.map( function ( option ) {
						return el( 'option', { value: option, key: option }, option );
					} )
				)
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var options = ( a.options || '' )
				.split( /\n/ )
				.map( function ( item ) {
					return item.trim();
				} )
				.filter( Boolean );

			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-select',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				bdfrmsSaveLabelIfAny( a.name, a.label ),
				el(
					'select',
					{
						name: a.name,
						id: a.name,
						required: !! a.required,
					},
					options.map( function ( option ) {
						return el( 'option', { value: option, key: option }, option );
					} )
				)
			);
		},
	} );

	registerBlockType( 'bdfrms/field-checkbox', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'zustimmung', BDFRMS_LEGACY_NAMES_CHECKBOX );
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-checkbox',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Checkbox', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false )
					)
				),
				( function () {
					var lab = bdfrmsTrimmedFieldLabel( attributes.label );
					var nm = attributes.name || '';
					var input = el( 'input', {
						type: 'checkbox',
						disabled: true,
						name: nm || undefined,
						id: nm || undefined,
						value: '1',
					} );
					if ( lab ) {
						if ( attributes.required ) {
							return el( 'label', { for: nm || undefined }, input, ' ', lab, el( 'span', { className: 'bdfrms-required', 'aria-hidden': 'true' }, ' *' ) );
						}
						return el( 'label', { for: nm || undefined }, input, ' ', lab );
					}
					return input;
				}() )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-checkbox',
				style: buildFieldColorOverrideStyle( a ),
			} );
			var lab = bdfrmsTrimmedFieldLabel( a.label );
			var input = el( 'input', {
				type: 'checkbox',
				name: a.name,
				id: a.name,
				value: '1',
				required: !! a.required,
			} );
			return el(
				'div',
				saveProps,
				lab ? el( 'label', { for: a.name }, input, ' ', lab ) : input
			);
		},
	} );

	registerBlockType( 'bdfrms/field-submit', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-submit',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Absenden-Button', 'blitz-donner-forms' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Button-Text', 'blitz-donner-forms' ),
							value: attributes.label || '',
							onChange: function ( value ) {
								setAttributes( { label: value } );
							},
						} )
					)
				),
				el(
					'div',
					{ className: 'wp-block-button is-style-default' },
					el(
						'button',
						{
							type: 'submit',
							disabled: true,
							className: 'wp-block-button__link wp-element-button',
						},
						attributes.label || __( 'Formular absenden', 'blitz-donner-forms' )
					)
				)
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-submit',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				el(
					'div',
					{ className: 'wp-block-button is-style-default' },
					el(
						'button',
						{
							type: 'submit',
							className: 'wp-block-button__link wp-element-button',
						},
						a.label || __( 'Formular absenden', 'blitz-donner-forms' )
					)
				)
			);
		},
	} );

	registerBlockType( 'bdfrms/field-number', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, true, 'zahl', BDFRMS_LEGACY_NAMES_NUMBER );
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-number',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Zahl', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, true ),
						buildMinMaxStepInspector( attributes, setAttributes, true )
					)
				),
				bdfrmsEditorLabelIfAny( 'label', bdfrmsLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'number',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					placeholder: attributes.placeholder || '',
					min: attributes.min || undefined,
					max: attributes.max || undefined,
					step: attributes.step || undefined,
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-number',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				bdfrmsSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'number',
					name: a.name,
					id: a.name,
					placeholder: a.placeholder || '',
					min: a.min || undefined,
					max: a.max || undefined,
					step: a.step || undefined,
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'bdfrms/field-tel', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, true, 'telefon', BDFRMS_LEGACY_NAMES_TEL );
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-tel',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Telefon', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, true )
					)
				),
				bdfrmsEditorLabelIfAny( 'label', bdfrmsLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'tel',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					placeholder: attributes.placeholder || '',
					autoComplete: 'tel',
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-tel',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				bdfrmsSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'tel',
					name: a.name,
					id: a.name,
					placeholder: a.placeholder || '',
					required: !! a.required,
					autoComplete: 'tel',
				} )
			);
		},
	} );

	registerBlockType( 'bdfrms/field-url', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, true, 'website', BDFRMS_LEGACY_NAMES_URL );
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-url',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'URL', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, true )
					)
				),
				bdfrmsEditorLabelIfAny( 'label', bdfrmsLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'url',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					placeholder: attributes.placeholder || '',
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-url',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				bdfrmsSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'url',
					name: a.name,
					id: a.name,
					placeholder: a.placeholder || '',
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'bdfrms/field-date', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'datum', BDFRMS_LEGACY_NAMES_DATE );
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-date',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Datum', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						buildDateMinMaxInspector( attributes, setAttributes ),
						buildOptionalDefaultValueControl(
							attributes,
							setAttributes,
							__(
								'Leer lassen für keinen Startwert. Format: JJJJ-MM-TT, z. B. 2026-05-10.',
								'blitz-donner-forms'
							)
						)
					)
				),
				bdfrmsEditorLabelIfAny( 'label', bdfrmsLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'date',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					min: attributes.min || undefined,
					max: attributes.max || undefined,
					defaultValue: attributes.defaultValue || undefined,
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-date',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				bdfrmsSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'date',
					name: a.name,
					id: a.name,
					min: a.min || undefined,
					max: a.max || undefined,
					value: a.defaultValue || undefined,
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'bdfrms/field-time', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'uhrzeit', BDFRMS_LEGACY_NAMES_TIME );
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-time',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Uhrzeit', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						buildOptionalDefaultValueControl(
							attributes,
							setAttributes,
							__(
								'Leer lassen für keinen Startwert. Format: HH:MM (24 Stunden), z. B. 09:30.',
								'blitz-donner-forms'
							)
						)
					)
				),
				bdfrmsEditorLabelIfAny( 'label', bdfrmsLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'time',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					defaultValue: attributes.defaultValue || undefined,
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-time',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				bdfrmsSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'time',
					name: a.name,
					id: a.name,
					value: a.defaultValue || undefined,
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'bdfrms/field-datetime', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'termin', BDFRMS_LEGACY_NAMES_DATETIME );
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-datetime',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Datum und Uhrzeit', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						buildOptionalDefaultValueControl(
							attributes,
							setAttributes,
							__(
								'Leer lassen für keinen Startwert. Format: JJJJ-MM-TTTHH:MM (lokal), z. B. 2026-05-10T14:00.',
								'blitz-donner-forms'
							)
						)
					)
				),
				bdfrmsEditorLabelIfAny( 'label', bdfrmsLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'datetime-local',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					defaultValue: attributes.defaultValue || undefined,
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-datetime',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				bdfrmsSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'datetime-local',
					name: a.name,
					id: a.name,
					value: a.defaultValue || undefined,
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'bdfrms/field-radio', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'radio', BDFRMS_LEGACY_NAMES_RADIO );
			var options = ( attributes.options || '' )
				.split( /\n/ )
				.map( function ( item ) {
					return item.trim();
				} )
				.filter( Boolean );
			var radioLayout = attributes.optionsLayout === 'row' ? 'row' : 'column';
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-radio',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			var radioOptsClass =
				'bdfrms-radio-options' +
				( radioLayout === 'row' ? ' bdfrms-radio-options--row' : '' );
			var radioGroupLabelText = bdfrmsTrimmedFieldLabel( attributes.label );
			var previewName = 'bdfrms-preview-' + String( attributes.name || 'radio' );
			var radioPreviewBody = [
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Radio-Auswahl', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						el( TextareaControl, {
							label: __( 'Optionen (eine pro Zeile)', 'blitz-donner-forms' ),
							value: attributes.options || '',
							onChange: function ( value ) {
								setAttributes( { options: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Anordnung der Optionen', 'blitz-donner-forms' ),
							value: radioLayout,
							options: [
								{ label: __( 'Untereinander', 'blitz-donner-forms' ), value: 'column' },
								{ label: __( 'Nebeneinander', 'blitz-donner-forms' ), value: 'row' },
							],
							onChange: function ( value ) {
								setAttributes( { optionsLayout: value === 'row' ? 'row' : 'column' } );
							},
						} )
					)
				),
			];
			if ( radioGroupLabelText ) {
				radioPreviewBody.push(
					attributes.required
						? el( 'legend', null, radioGroupLabelText, el( 'span', { className: 'bdfrms-required', 'aria-hidden': 'true' }, ' *' ) )
						: el( 'legend', null, radioGroupLabelText )
				);
			}
			radioPreviewBody.push(
				el(
					'div',
					{ className: radioOptsClass },
					options.map( function ( opt, idx ) {
						var rid = String( attributes.name || 'radio' ) + '_' + idx;
						return el(
							'div',
							{ key: opt, className: 'bdfrms-radio-row' },
							el( 'input', {
								type: 'radio',
								disabled: true,
								name: previewName,
								id: rid,
								value: opt,
							} ),
							' ',
							el( 'label', { for: rid }, opt )
						);
					} )
				)
			);
			return el( 'fieldset', blockProps, radioPreviewBody );
		},
		// Dynamischer Block: render_callback in PHP übernimmt das Frontend-HTML.
		// save() gibt null zurück; das frühere statische HTML lebt in deprecated[].
		save: function () {
			return null;
		},
		deprecated: [
			{
				// v2.6.4 und früher: save() erzeugte statisches HTML.
				// Split robuster gemacht (auch \r\n und Buchstabe n als Fallback).
				attributes: {
					label:         { type: 'string', default: 'Auswahl' },
					name:          { type: 'string', default: '' },
					nameClientId:  { type: 'string', default: '' },
					options:       { type: 'string', default: 'Option A\nOption B\nOption C' },
					required:      { type: 'boolean', default: false },
					optionsLayout: { type: 'string', default: 'column' },
					sensitive:     { type: 'boolean', default: false },
				},
				save: function ( props ) {
					var a = props.attributes;
					var options = ( a.options || '' )
						.split( /\r\n|\r|\n/ )
						.map( function ( item ) { return item.trim(); } )
						.filter( Boolean );
					var layout = a.optionsLayout === 'row' ? 'row' : 'column';
					var optionsWrapClass =
						'bdfrms-radio-options' + ( layout === 'row' ? ' bdfrms-radio-options--row' : '' );
					var saveProps = useBlockPropsSave( {
						className: 'bdfrms-field bdfrms-field-radio',
						style: buildFieldColorOverrideStyle( a ),
					} );
					var groupLab = bdfrmsTrimmedFieldLabel( a.label );
					return el(
						'fieldset',
						saveProps,
						groupLab ? el( 'legend', null, groupLab ) : null,
						el(
							'div',
							{ className: optionsWrapClass },
							options.map( function ( opt, idx ) {
								var id = a.name + '_' + idx;
								return el(
									'div',
									{ key: opt, className: 'bdfrms-radio-row' },
									el( 'input', {
										type: 'radio',
										name: a.name,
										id: id,
										value: opt,
										required: !! a.required && idx === 0,
									} ),
									' ',
									el( 'label', { for: id }, opt )
								);
							} )
						)
					);
				},
			},
		],
	} );

	registerBlockType( 'bdfrms/field-hidden', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'hidden', BDFRMS_LEGACY_NAMES_HIDDEN );
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-hidden',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Verstecktes Feld', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						el( TextControl, {
							label: __( 'Label (Hinweis)', 'blitz-donner-forms' ),
							value: attributes.label || '',
							onChange: function ( value ) {
								setAttributes( { label: value } );
							},
							help: __(
								'Nur im Editor und in der Eintrags-Übersicht sichtbar; erscheint nicht im Formular.',
								'blitz-donner-forms'
							),
						} ),
						el( TextControl, {
							label: __( 'Wert', 'blitz-donner-forms' ),
							value: attributes.hiddenValue || '',
							onChange: function ( value ) {
								setAttributes( { hiddenValue: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Wert serverseitig erzwingen (empfohlen)', 'blitz-donner-forms' ),
							help: __(
								'Wenn aktiv, wird der oben gesetzte Wert beim Absenden serverseitig gesetzt. Werte aus dem Browser werden ignoriert (Schutz gegen Manipulation).',
								'blitz-donner-forms'
							),
							checked: !! attributes.lockedValue,
							onChange: function ( value ) {
								setAttributes( { lockedValue: !! value } );
							},
						} )
					)
				),
				el( 'input', {
					type: 'hidden',
					disabled: true,
					name: attributes.name || undefined,
					value: attributes.hiddenValue || '',
					'aria-label':
						bdfrmsTrimmedFieldLabel( attributes.label ) ||
						__( 'Verstecktes Feld (nur technischer Wert)', 'blitz-donner-forms' ),
				} )
			);
		},
		// Dynamischer Block: render_callback in PHP übernimmt das Frontend-HTML.
		// save() gibt null zurück; das frühere statische HTML lebt in deprecated[].
		save: function () {
			return null;
		},
		deprecated: [
			{
				// v2.6.4 und früher: save() erzeugte statisches HTML.
				attributes: {
					label:        { type: 'string', default: '' },
					name:         { type: 'string', default: '' },
					nameClientId: { type: 'string', default: '' },
					hiddenValue:  { type: 'string', default: '' },
					lockedValue:  { type: 'boolean', default: false },
					sensitive:    { type: 'boolean', default: false },
				},
				save: function ( props ) {
					var a = props.attributes;
					var saveProps = useBlockPropsSave( {
						className: 'bdfrms-field bdfrms-field-hidden',
					} );
					return el(
						'div',
						saveProps,
						el( 'input', {
							type: 'hidden',
							name: a.name,
							value: a.hiddenValue || '',
						} )
					);
				},
			},
		],
	} );

	registerBlockType( 'bdfrms/field-range', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'wert', BDFRMS_LEGACY_NAMES_RANGE );
			var min = attributes.min || '0';
			var max = attributes.max || '100';
			var step = attributes.step || '1';
			var def = attributes.defaultValue;
			var initial = computeRangeInitial( min, max, step, def );
			var state = useState( initial );
			var sliderVal = state[0];
			var setSliderVal = state[1];
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-range',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			useEffect(
				function () {
					setSliderVal( computeRangeInitial( min, max, step, def ) );
				},
				[ min, max, step, def ]
			);
			var rangeId =
				attributes.name && String( attributes.name ).trim() !== ''
					? String( attributes.name ).trim()
					: 'bdfrms-range-preview-' + props.clientId;
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Schieberegler', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						buildMinMaxStepInspector( attributes, setAttributes, true ),
						el( TextControl, {
							label: __( 'Startwert (optional)', 'blitz-donner-forms' ),
							value: attributes.defaultValue || '',
							onChange: function ( value ) {
								setAttributes( { defaultValue: value } );
							},
							help: __( 'Leer lassen für die Mitte zwischen Min und Max.', 'blitz-donner-forms' ),
						} )
					)
				),
				bdfrmsEditorLabelIfAny( 'label', bdfrmsLabelForProps( rangeId ), attributes.label, attributes.required ),
				el(
					'div',
					{ className: 'bdfrms-range-row' },
					el( 'input', {
						id: rangeId,
						name: attributes.name || undefined,
						type: 'range',
						min: min,
						max: max,
						step: step,
						value: sliderVal,
						onChange: function ( ev ) {
							setSliderVal( ev.target.value );
						},
					} ),
					el( 'output', { className: 'bdfrms-range-value', htmlFor: rangeId }, sliderVal )
				)
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var startVal = computeRangeInitial( a.min || '0', a.max || '100', a.step || '1', a.defaultValue );
			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-range',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				bdfrmsSaveLabelIfAny( a.name, a.label ),
				el(
					'div',
					{ className: 'bdfrms-range-row' },
					el( 'input', {
						type: 'range',
						name: a.name,
						id: a.name,
						min: a.min || '0',
						max: a.max || '100',
						step: a.step || '1',
						value: startVal,
						required: !! a.required,
					} ),
					el( 'output', { className: 'bdfrms-range-value', htmlFor: a.name }, startVal )
				)
			);
		},
	} );

	registerBlockType( 'bdfrms/field-file', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'datei', BDFRMS_LEGACY_NAMES_FILE );
			var blockProps = useBlockProps( {
				className: 'bdfrms-field bdfrms-field-file',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Datei-Upload', 'blitz-donner-forms' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						el( TextControl, {
							label: __( 'accept (z. B. .pdf,image/*)', 'blitz-donner-forms' ),
							value: attributes.accept || '',
							onChange: function ( value ) {
								setAttributes( { accept: value } );
							},
						} ),
						__experimentalNumberControl
							? el( __experimentalNumberControl, {
									label: __( 'Max. Grösse (MB)', 'blitz-donner-forms' ),
									value: attributes.maxSizeMb || 8,
									onChange: function ( value ) {
										var parsed = parseInt( value, 10 );
										if ( Number.isNaN( parsed ) ) {
											return;
										}
										setAttributes( { maxSizeMb: Math.min( 128, Math.max( 1, parsed ) ) } );
									},
									min: 1,
									max: 128,
							  } )
							: null
					)
				),
				bdfrmsEditorLabelIfAny( 'label', bdfrmsLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'file',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					accept: attributes.accept || undefined,
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'bdfrms-field bdfrms-field-file',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				bdfrmsSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'file',
					name: a.name,
					id: a.name,
					required: !! a.required,
					accept: a.accept || undefined,
				} )
			);
		},
	} );

	if ( typeof window !== 'undefined' && wp && wp.data && wp.data.subscribe ) {
		var bdfrmsStyleSyncTimer = null;
		wp.data.subscribe( function () {
			if ( bdfrmsStyleSyncTimer ) {
				clearTimeout( bdfrmsStyleSyncTimer );
			}
			bdfrmsStyleSyncTimer = setTimeout( function () {
				bdfrmsStyleSyncTimer = null;
				bdfrmsSyncEditorFormStylesheet();
			}, 80 );
		} );
		if ( wp.domReady ) {
			wp.domReady( function () {
				setTimeout( bdfrmsSyncEditorFormStylesheet, 150 );
				setTimeout( bdfrmsSyncEditorFormStylesheet, 600 );
			} );
		}
	}
} )( window.wp );
