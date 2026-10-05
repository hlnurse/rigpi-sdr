#!/usr/bin/env python3
"""Make a manual DX Call field change use RigPi's central settings path."""

import argparse
from pathlib import Path


HOOK = r'''(function(){
  const validCall=/^(?:[A-Z0-9]{1,3}\/)?[A-Z0-9]{1,3}\d[A-Z]{1,4}(?:\/[A-Z0-9]{1,4})?$/;
  document.addEventListener('change',function(event){
    const field=event.target;
    if(!field||field.id!=='searchText')return;
    const call=String(field.value||'').trim().toUpperCase();
    field.value=call;
    if(!validCall.test(call)||field.dataset.elmerDxSaved===call)return;
    field.dataset.elmerDxSaved=call;
    const radio=Number(window.tMyRadio||window.rigPiRadio||1)||1;
    fetch('/programs/SetSettings.php',{
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:new URLSearchParams({field:'DX',radio:String(radio),data:call,table:'MySettings'}).toString()
    }).then(function(response){
      if(!response.ok)throw new Error('RigPi could not update DX Call.');
    }).catch(function(error){
      field.dataset.elmerDxSaved='';
      console.warn('DX Call callbook refresh failed:',error);
    });
  },true);
})();
'''


def patch(path):
    target = Path(path)
    text = target.read_text(encoding='utf-8')
    if "field.dataset.elmerDxSaved" in text:
        return False
    anchor = "<script>\nfunction showSettings(){"
    if text.count(anchor) != 1:
        raise RuntimeError(f'Unable to locate the shared header script in {target}')
    target.write_text(text.replace(anchor, '<script>\n' + HOOK + 'function showSettings(){', 1), encoding='utf-8')
    return True


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--target', default='/var/www/html/includes/header.php')
    args = parser.parse_args()
    print('Updated shared DX Call field behavior.' if patch(args.target)
          else 'Shared DX Call field behavior is already current.')


if __name__ == '__main__':
    main()
