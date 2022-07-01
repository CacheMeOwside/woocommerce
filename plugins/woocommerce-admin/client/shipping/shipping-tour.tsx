/**
 * External dependencies
 */
import { TourKit, TourKitTypes } from '@woocommerce/components';
import { __ } from '@wordpress/i18n';
import { useDispatch, useSelect } from '@wordpress/data';
import { OPTIONS_STORE_NAME } from '@woocommerce/data';

const REVIEWED_DEFAULTS_OPTION =
	'woocommerce_admin_reviewed_default_shipping_zones';

const CREATED_DEFAULTS_OPTION =
	'woocommerce_admin_created_default_shipping_zones';

const useShowShippingTour = () => {
	const {
		hasCreatedDefaultShippingZones,
		hasReviewedDefaultShippingOptions,
		isLoading,
	} = useSelect( ( select ) => {
		const { hasFinishedResolution, getOption } =
			select( OPTIONS_STORE_NAME );

		return {
			isLoading:
				! hasFinishedResolution( 'getOption', [
					CREATED_DEFAULTS_OPTION,
				] ) &&
				! hasFinishedResolution( 'getOption', [
					REVIEWED_DEFAULTS_OPTION,
				] ),
			hasCreatedDefaultShippingZones:
				getOption( CREATED_DEFAULTS_OPTION ) === 'yes',
			hasReviewedDefaultShippingOptions:
				getOption( REVIEWED_DEFAULTS_OPTION ) === 'yes',
		};
	} );

	return {
		isLoading,
		show:
			! isLoading &&
			hasCreatedDefaultShippingZones &&
			! hasReviewedDefaultShippingOptions,
	};
};

export const ShippingTour = () => {
	const { updateOptions } = useDispatch( OPTIONS_STORE_NAME );
	const { show: showTour } = useShowShippingTour();

	const tourConfig: TourKitTypes.WooConfig = {
		placement: 'auto',
		steps: [
			{
				referenceElements: {
					desktop: 'table.wc-shipping-zones',
				},
				meta: {
					name: 'shipping-zones',
					heading: 'Shipping zones',
					descriptions: {
						desktop: (
							<>
								<span>
									{ __(
										'We added a few shipping zones for you based on your location, but you can manage them at any time.',
										'woocommerce'
									) }
								</span>
								<br />
								<span>
									{ __(
										'A shipping zone is a geography area where a certain set of shippping methods are offered.',
										'woocommerce'
									) }
								</span>
							</>
						),
					},
				},
			},
			{
				referenceElements: {
					desktop: 'table.wc-shipping-zones',
				},
				meta: {
					name: 'shipping-methods',
					heading: 'Shipping methods',
					descriptions: {
						desktop: __(
							'We defaulted to some recommended shippping methods based on your store location, but you can manage them at any time within each shipping zone settings.',
							'woocommerce'
						),
					},
				},
			},
		],
		closeHandler: () => {
			updateOptions( {
				[ REVIEWED_DEFAULTS_OPTION ]: 'yes',
			} );
		},
	};
	if ( showTour ) {
		return (
			<div>
				<TourKit config={ tourConfig }></TourKit>
			</div>
		);
	}

	return null;
};
