import { __ } from '@wordpress/i18n';
import { date } from '@wordpress/date';
import {
	useBlockProps,
	InspectorControls
} from '@wordpress/block-editor';
import './editor.scss';
import {
	TextControl,
	__experimentalToggleGroupControl as ToggleGroupControl,
    __experimentalToggleGroupControlOption as ToggleGroupControlOption,
		ToggleControl,
	PanelBody,
	PanelRow,
	Spinner
 } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import {useState, useCallback} from '@wordpress/element';
import { debounce } from '@wordpress/compose';

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#edit
 *
 * @return {WPElement} Element to render.
 */
export default function Edit( props ) {

	// set vars.
	const { setAttributes } = props;
	const { location, measurementunit, showHourly } = props.attributes;
	const initLocation = location ? location : null;
	const [apiData, setApiData] = useState( null );
	const [isLoading, setIsLoading] = useState( false );
	const blockProps = useBlockProps();

	// getData function.
	const getData = async ( location ) => {
		setIsLoading( true );
		const path = 'weatherblock/v1/weatherdata/' + encodeURIComponent( location );
		//console.log( path );

		const response = await apiFetch( {
			path: path
		} );
		let parsedResponse = JSON.parse( response );
    setApiData( parsedResponse );
		setIsLoading( false) ;
	}

	// set debounced function.
	const debouncedGetData = useCallback( debounce( getData, 500 ), [] );

	// get data if we have a location.
	if ( initLocation && null === apiData ) {
		debouncedGetData( location );
	}

	// set new location on field change.
	const setNewLocation = ( value ) => {
		setAttributes( { location: value } );

		if ('' !== value) {
			debouncedGetData( value );
		} else {
			setApiData( null );
		}
	}

	const units = {
		imperial: {
			tempunit: 'f',
			speedunit: 'mph',
		},
		metric: {
			tempunit: 'c',
			speedunit: 'kph',
		}
	};

	const tempUnit = units[measurementunit].tempunit;
	const tempDisplayUnit = 'temp_' + tempUnit;
	const speedUnit = units[measurementunit].speedunit;

	return (
		<>
			<InspectorControls>
					<PanelRow>
						<PanelBody title={ __( 'Block Options', 'weatherblock' ) }>
							<TextControl
								label={ __('Enter city name or post code', 'weatherblock' ) }
								help={ __( '*Required', 'weatherblock' ) }
								value={location}
								onChange={ ( newLocation ) => { setNewLocation(newLocation) } }
							/>
							<ToggleGroupControl
								label={ __( 'Measurement Unit', 'weatherblock' ) }
								value={ measurementunit }
								onChange={ ( newUnit ) => setAttributes( { measurementunit: newUnit } ) }
							>
								<ToggleGroupControlOption value="imperial" label="Imperial" />
								<ToggleGroupControlOption value="metric" label="Metric" />
							</ToggleGroupControl>
							<ToggleControl
								label={__( 'Show Hourly Forecast?', 'weatherblock' )}
								value={ showHourly }
								checked={ showHourly }
								onChange={ ( newShowHourly ) => setAttributes( { showHourly: newShowHourly } ) }
							/>
						</PanelBody>
					</PanelRow>
				</InspectorControls>
			<div {...blockProps}>
				{!location && (
					<p>Location is required.</p>
				)}
				{isLoading && <Spinner />}
				{apiData && apiData.error && (
					<p>{apiData.error.message}</p>
				)}
				{apiData && !isLoading && !apiData.error && (
					<div class="weather-block">
						<header>
							<h2>{apiData.location.name}, <span>{apiData.location.region}</span></h2>
						</header>
						<div className="today">
							<div className="current-conditions">
								<div class="icon">
									<img src={apiData.current.condition.icon} alt={apiData.current.condition.text} />
								</div>
								<div class="weather-data">
									<p class="current-temp">{Math.round( apiData.current[tempDisplayUnit] )}&deg; <span>{tempUnit.toUpperCase()}</span></p>
									<p class="feels-like">{__('Feels like', 'weatherblock' )} {Math.round( apiData.current['feelslike_' + tempUnit] )}&deg; <span>{tempUnit.toUpperCase()}</span></p>
								</div>
								<div class="weather-meta">
									<p>{__('Precipitation', 'weatherblock' )}: {apiData.forecast.forecastday[0].day.daily_chance_of_rain}%</p>
									<p>{__( 'Humidity', 'weatherblock' )}: {apiData.current.humidity}%</p>
									<p>{__( 'Wind', 'weatherblock' )}: {apiData.current['wind_' + speedUnit]}{speedUnit}</p>
								</div>
								<div className="weather-datetime">
									<p class="last-updated-date">{date('D F j, Y', new Date(apiData.current.last_updated_epoch * 1000))}</p>
									<p className="last-updated-time">{date('g:i A', new Date(apiData.current.last_updated_epoch * 1000))}</p>
									<p>{apiData.current.condition.text}</p>
								</div>
							</div>
						</div>
						{showHourly && (
							<div className="forecast">
								<h3>{__('Hourly', 'weatherblock')}</h3>
								<ul>
								{apiData.forecast.forecastday[0].hour.map((hour, index) => (
									 hour.time_epoch > Math.floor(new Date().getTime() / 1000) && (
										<li key={index}>
											<p class="temp">{Math.round( hour[tempDisplayUnit] )}&deg; <span>{tempUnit.toUpperCase()}</span></p>
											<img src={hour.condition.icon} alt={hour.condition.text} />
											<p>{date('g:i A', new Date(hour.time_epoch * 1000))}</p>
										</li>
									)
								))}
								</ul>
							</div>
						)}
					</div>
				)}
			</div>
		</>
	);

}
