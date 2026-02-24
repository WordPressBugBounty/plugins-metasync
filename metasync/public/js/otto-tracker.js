// Performance optimized: Run immediately without waiting for DOMContentLoaded
// Only execute for bot traffic to minimize performance impact on real users
(function () {
	'use strict';

	// Early exit if no OTTO data configured
	if (!window.saOttoData) {
		return;
	}

	// Early exit if not a bot - saves execution time for real users
	const saDyoUseragent = navigator.userAgent;
	const isBot = /bot|crawl|spider|slurp|mediapartners/i.test(saDyoUseragent);

	if (!isBot) {
		return; // Skip execution for non-bot traffic to improve performance
	}

	const { page_url, otto_uuid, context } = window.saOttoData;
	const postPageCrawlLogs = async (pageUrl, uuid, context) => {
		try {
			if (isBot) {
				const saDyoApiUrl = 'https://sa.searchatlas.com/api/v2/otto-page-crawl-logs/';
				const saDyoBodyData = {
					otto_uuid: uuid,
					url: pageUrl,
					user_agent: saDyoUseragent,
					context: context
				};

				try {
					const saDyoResources = performance.getEntriesByType('resource');
					const saDyoTotalResponseTime = saDyoResources.reduce((sum, r) => sum + (r.responseEnd - r.startTime), 0);
					const saDyoAverageResponseTime = (saDyoResources.length > 0)
						? (saDyoTotalResponseTime / saDyoResources.length).toFixed(2)
						: null;
					const saDyoTotalDownloadSize = saDyoResources.reduce((sum, r) => sum + (r.transferSize || 0), 0);
					const saDyoTotalDownloadSizeKB = (saDyoTotalDownloadSize / 1024).toFixed(2);

					if (saDyoAverageResponseTime) {
						saDyoBodyData.average_response_time = saDyoAverageResponseTime;
					}
					if (saDyoTotalDownloadSizeKB) {
						saDyoBodyData.total_download_size_kb = saDyoTotalDownloadSizeKB;
					}
				} catch (error) {

				}

				const saDyoResponse = await fetch(saDyoApiUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json'
					},
					body: JSON.stringify(saDyoBodyData)
				});

				if (!saDyoResponse.ok) {
					return;
				}
			}
		} catch (error) {

		}
	};

	// Use requestIdleCallback for better performance if available
	// Falls back to setTimeout for browsers that don't support it
	if ('requestIdleCallback' in window) {
		requestIdleCallback(() => {
			postPageCrawlLogs(page_url, otto_uuid, context);
		}, { timeout: 2000 });
	} else {
		setTimeout(() => {
			postPageCrawlLogs(page_url, otto_uuid, context);
		}, 100);
	}
})();
