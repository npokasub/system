app.directive('prettyError', function ($timeout) {
	'use strict';
	return {
		scope: {
			errors: '=',
		},
		link: function (scope, elm, attrs) {},
		template: '<ul class="list-group" ng-show="errors.summaryReport.length ||errors.message">'
				+ '<li class="list-group-item h4 list-group-item-danger">Please solve the problems below</li>'
				+ '<li class="errorMessage list-group-item" ng-repeat="m in errors.summaryReport track by $index">{{m}}</li>'
				+ '<li class="errorMessage list-group-item" ng-show="errors.message">{{errors.message}}</li>'
				+ '</ul>'

	};
})