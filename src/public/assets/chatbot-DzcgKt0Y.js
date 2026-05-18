const d="biiglebot-modal",c="biiglebot-open-button",f="/biiglebot/chat";let l=[],m=!1;function p(){const t=document.querySelector('meta[name="csrf-token"]');return t?t.getAttribute("content"):""}function r(t,e){return{role:t,content:e}}function a(t,e,n){const o=document.createElement("div");o.className=`biiglebot-message biiglebot-message--${e}`;const s=document.createElement("span");s.className="biiglebot-message__role",s.textContent=e==="assistant"?"BIIGLEBot":e==="user"?"You":"Error";const i=document.createElement("span");i.className="biiglebot-message__content",i.textContent=n,o.appendChild(s),o.appendChild(i),t.appendChild(o),t.scrollTop=t.scrollHeight}function E(t){t.innerHTML="",l.forEach(e=>{a(t,e.role,e.content)})}function u(t){m=t;const e=document.getElementById("biiglebot-send"),n=document.getElementById("biiglebot-clear"),o=document.getElementById("biiglebot-input");e&&(e.disabled=t,e.textContent=t?"Sending...":"Send"),n&&(n.disabled=t),o&&(o.disabled=t)}async function b(){if(m)return;const t=document.getElementById("biiglebot-input"),e=document.getElementById("biiglebot-messages");if(!t||!e)return;const n=t.value.trim();if(n){l.push(r("user",n)),a(e,"user",n),t.value="",u(!0);try{const o=await fetch(f,{method:"POST",headers:{Accept:"application/json","Content-Type":"application/json","X-CSRF-TOKEN":p()},body:JSON.stringify({message:n,history:l.slice(-20)})}),s=await o.json();if(!o.ok){const g=s&&s.message?s.message:"Request failed.";a(e,"error",g);return}const i=s&&s.assistant?s.assistant:"";l.push(r("assistant",i)),a(e,"assistant",i)}catch{a(e,"error","Could not reach BIIGLEBot backend.")}finally{u(!1)}}}function y(){l=[];const t=document.getElementById("biiglebot-messages");t&&E(t)}function I(){if(document.getElementById(d))return;const t=document.createElement("div");t.id=d,t.className="modal fade",t.tabIndex=-1,t.setAttribute("role","dialog"),t.innerHTML=`
<div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title">BIIGLEBot</h4>
        </div>
        <div class="modal-body">
            <div id="biiglebot-messages" class="biiglebot-messages"></div>
            <div class="biiglebot-input">
                <textarea id="biiglebot-input" class="form-control" rows="3" placeholder="Ask BIIGLEBot..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" id="biiglebot-clear" class="btn btn-default">Clear</button>
            <button type="button" id="biiglebot-send" class="btn btn-primary">Send</button>
        </div>
    </div>
</div>`,document.body.appendChild(t);const e=document.getElementById("biiglebot-send"),n=document.getElementById("biiglebot-clear"),o=document.getElementById("biiglebot-input");e&&e.addEventListener("click",b),n&&n.addEventListener("click",y),o&&o.addEventListener("keydown",i=>{i.key==="Enter"&&!i.shiftKey&&(i.preventDefault(),b())});const s=window.jQuery||window.$;s&&s(`#${d}`).on("shown.bs.modal",()=>{const i=document.getElementById("biiglebot-input");i&&i.focus()})}function h(){I();const t=window.jQuery||window.$;t&&t(`#${d}`).modal("show")}function B(){if(document.getElementById(c))return;const t=document.getElementById("navbar-right");if(!t)return;const e=t.querySelector("ul.navbar-nav");if(!e)return;const n=document.createElement("li");n.id=c,n.innerHTML=`
<a href="#" class="navbar-btn-link" title="Open BIIGLEBot">
    <span class="btn btn-default">
        <i class="fa fa-comments"></i>
    </span>
</a>`,n.addEventListener("click",s=>{s.preventDefault(),h()});const o=e.querySelector('li[is="vue:dropdown"]');o?e.insertBefore(n,o):e.appendChild(n)}B();
