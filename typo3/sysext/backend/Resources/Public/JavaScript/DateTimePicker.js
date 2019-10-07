/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
define(["require","exports","jquery","moment","./Storage/Persistent"],function(e,t,a,i,r){"use strict";class o{constructor(){this.fieldSelector=".t3js-datetimepicker",this.format=(null!=opener&&void 0!==opener.top.TYPO3?opener.top:top).TYPO3.settings.DateTimePicker.DateFormat,a(()=>{this.initialize()})}static formatDateForHiddenField(e,t){return"time"!==t&&"timesec"!==t||e.year(1970).month(0).date(1),e.format()}initialize(){const t=a(this.fieldSelector).filter((e,t)=>void 0===a(t).data("DateTimePicker"));t.length>0&&e(["twbs/bootstrap-datetimepicker"],()=>{let e=r.get("lang");"ch"===e&&(e="zh-cn");const d=!!e&&i.locale(e);t.each((e,t)=>{this.initializeField(a(t),d)}),t.on("blur",e=>{const t=a(e.currentTarget),r=t.parent().parent().find('input[type="hidden"]');if(""===t.val())r.val("");else{const e=t.data("dateType"),a=t.data("DateTimePicker").format(),d=i.utc(t.val(),a);d.isValid()?r.val(o.formatDateForHiddenField(d,e)):t.val(o.formatDateForHiddenField(i.utc(r.val()),e))}}),t.on("dp.change",e=>{const t=a(e.currentTarget),i=t.parent().parent().find("input[type=hidden]"),r=t.data("dateType");let d="";""!==t.val()&&(d=o.formatDateForHiddenField(e.date.utc(),r)),i.val(d),a(document).trigger("formengine.dp.change",[t])})})}initializeField(e,t){const a=this.format,r={format:"",locale:"",sideBySide:!0,showTodayButton:!0,toolbarPlacement:"bottom",icons:{time:"fa fa-clock-o",date:"fa fa-calendar",up:"fa fa-chevron-up",down:"fa fa-chevron-down",previous:"fa fa-chevron-left",next:"fa fa-chevron-right",today:"fa fa-calendar-o",clear:"fa fa-trash"}};switch(e.data("dateType")){case"datetime":r.format=a[1];break;case"date":r.format=a[0];break;case"time":r.format="HH:mm";break;case"timesec":r.format="HH:mm:ss";break;case"year":r.format="YYYY"}e.data("dateMindate")&&e.data("dateMindate",i.unix(e.data("dateMindate")).format(r.format)),e.data("dateMaxdate")&&e.data("dateMaxdate",i.unix(e.data("dateMaxdate")).format(r.format)),t&&(r.locale=t),e.datetimepicker(r)}}return new o});