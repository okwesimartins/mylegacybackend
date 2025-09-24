


<div style="font-family: Helvetica,Arial,sans-serif;min-width:1000px;overflow:auto;line-height:2">
  <div style="margin:50px auto;width:70%;padding:20px 0">
    <div style="border-bottom:1px solid #eee">
      <!--<a href="" ><img src="https://codesandbox.com.ng/market_for_opticals/public/pictures/m4o_logo.png" alt="market for opticals logo"></a>-->
    </div>
    <div style="width:50%; border: 2px solid orange;border-radius:20px 9px">
        <img  style="max-width:100%;width:100%;height:auto!important;display:block;color:#555555;font-size:16px;border-radius:20px 9px" src="{{$transaction_details['image']}}" alt="product image" width="600" height="auto" border="0">
    </div>
    @if($transaction_details['type'] == "approve")
    <p style="font-size:1.1em">Hello, {{$admins_info['name']}}</p>
    <p>Your {{$transaction_details['product_name']}} product has just  been approved</p>
   @elseif($transaction_details['type'] == "suspend")
    <p style="font-size:1.1em">Hello, {{$admins_info['name']}}</p>
    <p>Your {{$transaction_details['product_name']}} product has just  been suspended from the opticals platform contact admin for more info</p>
   @endif
    <hr style="border:none;border-top:1px solid #eee" />
   
  </div>
</div>