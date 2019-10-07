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
define(["require","exports","jquery","TYPO3/CMS/Recordlist/LinkBrowser","TYPO3/CMS/Backend/Modal","ckeditor"],function(t,e,i,n,s){"use strict";class l{constructor(){this.plugin=null,this.CKEditor=null,this.siteUrl=""}initialize(t){let e=s.currentModal.data("ckeditor");if(void 0!==e)this.CKEditor=e;else{let e;e=void 0!==top.TYPO3.Backend&&void 0!==top.TYPO3.Backend.ContentContainer.get()?top.TYPO3.Backend.ContentContainer.get():window.parent,i.each(e.CKEDITOR.instances,(e,i)=>{i.id===t&&(this.CKEditor=i)})}i.extend(l,i("body").data()),i(".t3js-class-selector").on("change",()=>{i("option:selected",this).data("linkTitle")&&i(".t3js-linkTitle").val(i("option:selected",this).data("linkTitle"))}),i(".t3js-removeCurrentLink").on("click",t=>{t.preventDefault(),this.CKEditor.execCommand("unlink"),s.dismiss()})}finalizeFunction(t){const e=this.CKEditor.document.createElement("a"),l=n.getLinkAttributeValues(),r=l.params?l.params:"";l.target&&e.setAttribute("target",l.target),l.class&&e.setAttribute("class",l.class),l.title&&e.setAttribute("title",l.title),delete l.title,delete l.class,delete l.target,delete l.params,i.each(l,(t,i)=>{e.setAttribute(t,i)}),e.setAttribute("href",t+r);const a=this.CKEditor.getSelection();a&&""===a.getSelectedText()&&a.selectElement(a.getStartElement()),a&&a.getSelectedText()?e.setText(a.getSelectedText()):e.setText(e.getAttribute("href")),this.CKEditor.insertElement(e),s.dismiss()}}let r=new l;return n.finalizeFunction=(t=>{r.finalizeFunction(t)}),r});